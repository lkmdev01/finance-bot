<?php

namespace App\Services;

use App\Exceptions\AiUserTokenLimitExceeded;
use App\Models\AiUserTokenUsage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AiUserTokenBudget
{
    /** Run one provider call under a per-user/month lock and account its reported usage. */
    public function run(User $user, callable $operation): mixed
    {
        return $this->runAndAccount($user, $operation, fn (mixed $response): int => $this->reportedTokens($response));
    }

    /** Account legacy direct-HTTP providers from prompt/response size when SDK usage is unavailable. */
    public function runEstimated(User $user, string $prompt, callable $operation): mixed
    {
        return $this->runAndAccount($user, $operation, function (mixed $response) use ($prompt): int {
            $output = is_string($response) ? $response : '';

            return max(1, (int) ceil((mb_strlen($prompt, 'UTF-8') + mb_strlen($output, 'UTF-8')) / 4));
        });
    }

    private function runAndAccount(User $user, callable $operation, callable $tokenCounter): mixed
    {
        $limit = max(0, (int) config('ai.user_monthly_token_limit', 20000));
        $period = CarbonImmutable::now((string) config('app.timezone', 'UTC'))->format('Y-m');
        $lock = Cache::lock("ai-user-token-budget:{$user->getKey()}:{$period}", 180);

        return $lock->block(90, function () use ($user, $operation, $tokenCounter, $limit, $period): mixed {
            $usage = $this->usageRecord($user, $period);
            if ($limit > 0 && $usage->tokens_used >= $limit) {
                throw new AiUserTokenLimitExceeded($limit, $usage->tokens_used);
            }

            $response = $operation();
            $tokens = $tokenCounter($response);
            if ($tokens > 0) {
                $usage->increment('tokens_used', $tokens);
            }

            return $response;
        });
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

    private function reportedTokens(mixed $response): int
    {
        $usage = is_object($response) ? ($response->usage ?? null) : null;
        if (! is_object($usage)) {
            return 0;
        }

        // promptTokens includes the provider-reported prompt usage; reasoning and
        // cache details are subsets in the SDK's usage object and are not added twice.
        return max(0, (int) ($usage->promptTokens ?? 0) + (int) ($usage->completionTokens ?? 0));
    }
}
