<?php

namespace App\Services;

use App\Exceptions\AiUserTokenLimitExceeded;
use App\Models\AiTokenUsageEvent;
use App\Models\AiUserTokenUsage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AiUserTokenBudget
{
    /** Run one provider call under a per-user/month lock and account its reported usage. */
    public function run(User $user, callable $operation, string $agent = 'general'): mixed
    {
        return $this->runAndAccount($user, $operation, fn (mixed $response): array => $this->reportedUsage($response), $agent);
    }

    /** Account legacy direct-HTTP providers from prompt/response size when SDK usage is unavailable. */
    public function runEstimated(User $user, string $prompt, callable $operation, string $agent = 'financial'): mixed
    {
        return $this->runAndAccount($user, $operation, function (mixed $response) use ($prompt): array {
            $output = is_string($response) ? $response : '';
            $promptLength = mb_strlen($prompt, 'UTF-8');
            $outputLength = mb_strlen($output, 'UTF-8');
            $total = max(1, (int) ceil(($promptLength + $outputLength) / 4));
            $input = min($total, max(0, (int) ceil($promptLength / 4)));

            return ['input' => $input, 'output' => $total - $input, 'total' => $total, 'measurement' => 'estimated'];
        }, $agent);
    }

    private function runAndAccount(User $user, callable $operation, callable $tokenCounter, string $agent): mixed
    {
        $limit = max(0, (int) config('ai.user_monthly_token_limit', 20000));
        $period = CarbonImmutable::now((string) config('app.timezone', 'UTC'))->format('Y-m');
        $lock = Cache::lock("ai-user-token-budget:{$user->getKey()}:{$period}", 180);

        return $lock->block(90, function () use ($user, $operation, $tokenCounter, $limit, $period, $agent): mixed {
            $monthlyUsage = $this->usageRecord($user, $period);
            if (! $this->isExempt($user) && $limit > 0 && $monthlyUsage->tokens_used >= $limit) {
                throw new AiUserTokenLimitExceeded($limit, $monthlyUsage->tokens_used);
            }

            $response = $operation();
            $usage = $tokenCounter($response);
            $tokens = (int) ($usage['total'] ?? 0);
            if ($tokens > 0) {
                $monthlyUsage->increment('tokens_used', $tokens);
                $this->recordDetailedUsage($user, $agent, $usage);
            }

            return $response;
        });
    }

    public function isExempt(User $user): bool
    {
        $exemptIds = array_map('intval', (array) config('ai.user_monthly_token_exempt_ids', []));

        return in_array((int) $user->getKey(), $exemptIds, true);
    }

    public function hasReachedLimit(User $user): bool
    {
        $limit = max(0, (int) config('ai.user_monthly_token_limit', 20000));

        return ! $this->isExempt($user)
            && $limit > 0
            && $this->currentUsage($user) >= $limit;
    }

    public function currentUsage(User $user): int
    {
        $period = CarbonImmutable::now((string) config('app.timezone', 'UTC'))->format('Y-m');

        return (int) AiUserTokenUsage::query()
            ->where('user_id', $user->getKey())
            ->where('period', $period)
            ->value('tokens_used');
    }

    private function usageRecord(User $user, string $period): AiUserTokenUsage
    {
        DB::table('ai_user_token_usages')->insertOrIgnore([
            'user_id' => $user->getKey(),
            'period' => $period,
            'tokens_used' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return AiUserTokenUsage::query()
            ->where('user_id', $user->getKey())
            ->where('period', $period)
            ->firstOrFail();
    }

    /** @return array{input: int, output: int, total: int, measurement: string} */
    private function reportedUsage(mixed $response): array
    {
        $usage = is_object($response) ? ($response->usage ?? null) : null;
        if (! is_object($usage)) {
            return ['input' => 0, 'output' => 0, 'total' => 0, 'measurement' => 'reported'];
        }

        // promptTokens includes the provider-reported prompt usage; reasoning and
        // cache details are subsets in the SDK's usage object and are not added twice.
        $input = max(0, (int) ($usage->promptTokens ?? 0));
        $output = max(0, (int) ($usage->completionTokens ?? 0));

        return ['input' => $input, 'output' => $output, 'total' => $input + $output, 'measurement' => 'reported'];
    }

    /** @param array{input: int, output: int, total: int, measurement: string} $usage */
    private function recordDetailedUsage(User $user, string $agent, array $usage): void
    {
        $agentKey = Str::limit(Str::slug($agent) ?: 'general', 60, '');

        try {
            $event = AiTokenUsageEvent::create([
                'user_id' => $user->getKey(),
                'agent_key' => $agentKey,
                'input_tokens' => $usage['input'],
                'output_tokens' => $usage['output'],
                'total_tokens' => $usage['total'],
                'measurement' => $usage['measurement'],
            ]);

            app(AiTokenUsageSpikeAlertService::class)->check($user, $event);
        } catch (Throwable $exception) {
            Log::warning('Não foi possível registrar o detalhamento de uso de IA', [
                'user_id' => $user->getKey(),
                'agent_key' => $agentKey,
                'exception' => $exception::class,
            ]);
        }
    }
}
