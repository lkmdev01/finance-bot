<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AlertService
{
    public function __construct(
        private readonly PerformanceMetricsService $metrics,
        private readonly BaileysService $baileysService
    ) {}

    public function checkAlerts(): void
    {
        $this->checkWhatsAppConnection();
        $this->checkHighErrorRate();
        $this->checkAIResponseTime();
        $this->checkQueueSize();
    }

    private function checkWhatsAppConnection(): void
    {
        $recentMessages = \App\Models\WhatsAppContact::where('updated_at', '>=', now()->subMinutes(5))->count();

        if ($recentMessages === 0) {
            Log::warning('WhatsApp: Nenhuma mensagem processada nos ultimos 5 minutos');
        }
    }

    private function checkHighErrorRate(): void
    {
        $errorRate = $this->metrics->getErrorRate();
        $threshold = 0.1;

        if ($errorRate > $threshold) {
            $this->sendAlert('high_error_rate', [
                'error_rate' => $errorRate * 100,
                'threshold' => $threshold * 100,
                'message' => "Taxa de erro alta: {$errorRate}% (threshold: {$threshold}%)",
            ]);
        }
    }

    private function checkAIResponseTime(): void
    {
        $avgResponseTime = $this->metrics->getAverageResponseTime();
        $threshold = 5000;

        if ($avgResponseTime > $threshold) {
            $this->sendAlert('high_ai_response_time', [
                'response_time' => $avgResponseTime,
                'threshold' => $threshold,
                'message' => "Tempo de resposta da IA alto: {$avgResponseTime}ms (threshold: {$threshold}ms)",
            ]);
        }
    }

    private function checkQueueSize(): void
    {
        $queueSize = \Illuminate\Support\Facades\DB::table('jobs')->count();
        $threshold = 100;

        if ($queueSize > $threshold) {
            $this->sendAlert('high_queue_size', [
                'queue_size' => $queueSize,
                'threshold' => $threshold,
                'message' => "Fila de jobs grande: {$queueSize} jobs (threshold: {$threshold})",
            ]);
        }
    }

    private function sendAlert(string $type, array $data): void
    {
        Log::warning("Alert: {$type}", $data);

        AuditLog::create([
            'user_id' => null,
            'action' => 'alert',
            'model' => 'system',
            'model_id' => null,
            'metadata' => [
                'alert_type' => $type,
                ...$data,
            ],
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        if (config('mail.from.address')) {
            try {
                Mail::raw($data['message'], function ($message) use ($type) {
                    $message->to(config('mail.from.address'))
                        ->subject("Alerta: {$type}");
                });
            } catch (\Exception $e) {
                Log::error("Erro ao enviar email de alerta: {$e->getMessage()}");
            }
        }
    }

    public function checkExceededBudgets(): void
    {
        $users = \App\Models\User::whereHas('budgets')->get();

        foreach ($users as $user) {
            $exceededBudgets = $user->budgets()
                ->with('category')
                ->get()
                ->filter(function ($budget) {
                    $startOfMonth = now()->startOfMonth();
                    $endOfMonth = now()->endOfMonth();

                    $spent = $budget->user->transactions()
                        ->where('category_id', $budget->category_id)
                        ->where('type', 'expense')
                        ->whereBetween('date', [$startOfMonth, $endOfMonth])
                        ->sum('amount');

                    return $spent > $budget->amount;
                });

            foreach ($exceededBudgets as $budget) {
                $this->sendBudgetAlert($user, $budget);
            }
        }
    }

    private function sendBudgetAlert(\App\Models\User $user, \App\Models\Budget $budget): void
    {
        if ($this->budgetAlertAlreadySent($user->id, $budget->id)) {
            return;
        }

        $startOfMonth = now()->startOfMonth();
        $endOfMonth = now()->endOfMonth();

        $spent = $user->transactions()
            ->where('category_id', $budget->category_id)
            ->where('type', 'expense')
            ->whereBetween('date', [$startOfMonth, $endOfMonth])
            ->sum('amount');

        $exceededBy = $spent - $budget->amount;
        $contact = $user->whatsAppContacts()->first();

        if (! $contact) {
            return;
        }

        try {
            $message = "Alerta de Orcamento\n\n";
            $message .= "Voce excedeu o orcamento de {$budget->category->name}\n\n";
            $message .= 'Orcado: R$ '.number_format($budget->amount, 2, ',', '.')."\n";
            $message .= 'Gasto: R$ '.number_format($spent, 2, ',', '.')."\n";
            $message .= 'Excedido por: R$ '.number_format($exceededBy, 2, ',', '.')."\n";

            $this->baileysService->sendTextMessage($contact->phone_number, $message);
            $this->markBudgetAlertAsSent($user->id, $budget->id);
        } catch (\Exception $e) {
            Log::error("Erro ao enviar alerta de orcamento via WhatsApp: {$e->getMessage()}");
        }
    }

    private function budgetAlertAlreadySent(int $userId, int $budgetId): bool
    {
        return Cache::has($this->budgetAlertCacheKey($userId, $budgetId, now()->toDateString()));
    }

    private function markBudgetAlertAsSent(int $userId, int $budgetId): void
    {
        Cache::put(
            $this->budgetAlertCacheKey($userId, $budgetId, now()->toDateString()),
            true,
            now()->endOfDay()
        );
    }

    private function budgetAlertCacheKey(int $userId, int $budgetId, string $date): string
    {
        return "budget-alert:{$userId}:{$budgetId}:{$date}";
    }
}