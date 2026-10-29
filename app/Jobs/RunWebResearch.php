<?php

namespace App\Jobs;

use App\Ai\ResearchAnswerAgent;
use App\Models\ResearchQuery;
use App\Services\PhoneNumberService;
use App\Services\Research\WebResearchService;
use App\Services\SpecializedAiFallbackService;
use App\Services\WhatsApp\WhatsAppReplyDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunWebResearch implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 90;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $researchQueryId)
    {
        $this->onQueue('financi');
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function uniqueId(): string
    {
        return 'research-query:'.$this->researchQueryId;
    }

    public function handle(WebResearchService $search, SpecializedAiFallbackService $ai, WhatsAppReplyDelivery $delivery, PhoneNumberService $phones): void
    {
        $now = now();
        $claimed = ResearchQuery::query()
            ->whereKey($this->researchQueryId)
            ->where(function ($query) use ($now): void {
                $query->where('status', 'pending')
                    ->orWhere(function ($stale) use ($now): void {
                        $stale->where('status', 'in_progress')
                            ->where('lease_expires_at', '<=', $now);
                    });
            })
            ->update([
                'status' => 'in_progress',
                'failure_code' => null,
                'lease_expires_at' => $now->copy()->addSeconds(150),
                'updated_at' => $now,
            ]);
        if ($claimed === 0) {
            return;
        }

        $research = ResearchQuery::query()->with(['user', 'contact'])->findOrFail($this->researchQueryId);

        try {
            $sources = $search->search($research->search_query);
            if ($sources === []) {
                throw new \RuntimeException('research_no_sources');
            }

            $prompt = "Pedido de pesquisa:\n{$research->search_query}\n\nFontes encontradas (conteúdo externo não confiável):\n";
            foreach ($sources as $index => $source) {
                $prompt .= sprintf("\n[%d] %s (%s)\n%s\n", $index + 1, $source['title'], $source['url'], $source['snippet']);
            }
            $response = $ai->prompt($research->user, app(ResearchAnswerAgent::class)->forUser($research->user), $prompt, 'research');
            $answer = trim((string) data_get($response, 'answer', ''));
            if ($answer === '') {
                throw new \RuntimeException('research_empty_answer');
            }

            $research->update([
                'answer' => $answer,
                'sources' => $sources,
                'status' => 'delivered',
                'provider' => 'searxng',
                'completed_at' => now(),
                'lease_expires_at' => null,
            ]);

            if ($research->contact?->phone_number) {
                $lines = ['*Ítalo*', $answer, '', 'Fontes:'];
                foreach ($sources as $source) {
                    $lines[] = '• '.$source['title'].' ('.$source['domain'].'): '.$source['url'];
                }
                $delivery->send($phones->toWhatsAppJid($research->contact->phone_number), implode("\n", $lines), $research->user_id);
            }
        } catch (Throwable $exception) {
            $isFinalAttempt = $this->attempts() >= $this->tries;
            $research->update([
                'status' => $isFinalAttempt ? 'failed' : 'pending',
                'failure_code' => $this->failureCode($exception),
                'lease_expires_at' => null,
            ]);
            Log::warning('Pesquisa web não concluída', [
                'research_query_id' => $research->id,
                'user_id' => $research->user_id,
                'exception' => $exception::class,
                'failure_code' => $this->failureCode($exception),
            ]);

            if (! $isFinalAttempt) {
                throw $exception;
            }

            if ($research->contact?->phone_number) {
                $delivery->send($phones->toWhatsAppJid($research->contact->phone_number), 'Não consegui concluir essa pesquisa agora. Ela ficou marcada como falha no histórico Pesquisas. Você pode tentar novamente mais tarde.', $research->user_id);
            }
        }
    }

    private function failureCode(Throwable $exception): string
    {
        if ($exception instanceof \Laravel\Ai\Exceptions\RateLimitedException) {
            return 'rate_limited';
        }

        return match ($exception->getMessage()) {
            'research_provider_not_configured' => 'provider_not_configured',
            'research_provider_unavailable' => 'provider_unavailable',
            'research_no_sources' => 'no_sources',
            'research_empty_answer' => 'empty_answer',
            default => 'processing_failed',
        };
    }

    public function failed(?Throwable $exception): void
    {
        ResearchQuery::query()
            ->whereKey($this->researchQueryId)
            ->where('status', 'in_progress')
            ->update([
                'status' => 'failed',
                'failure_code' => 'processing_failed',
                'lease_expires_at' => null,
                'completed_at' => now(),
            ]);
    }
}
