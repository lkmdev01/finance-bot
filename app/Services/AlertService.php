<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Services\BaileysService;
use App\Services\PerformanceMetricsService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AlertService
{
    public function __construct(
        private readonly PerformanceMetricsService $metrics,
        private readonly BaileysService $baileysService
    ) {}

    /**
     * Verifica condições de alerta e envia notificações
     */
    public function checkAlerts(): void
    {
        $this->checkWhatsAppConnection();
        $this->checkHighErrorRate();
        $this->checkAIResponseTime();
        $this->checkQueueSize();
    }

    /**
     * Verifica se WhatsApp está desconectado
     */
    private function checkWhatsAppConnection(): void
    {
        // Verifica se há mensagens processadas recentemente
        $recentMessages = \App\Models\WhatsAppContact::where('updated_at', '>=', now()->subMinutes(5))->count();
        
        if ($recentMessages === 0) {
            // Pode estar desconectado, mas não alertamos imediatamente
            // Apenas logamos para monitoramento
            Log::warning('WhatsApp: Nenhuma mensagem processada nos últimos 5 minutos');
        }
    }

    /**
     * Verifica se taxa de erro está alta
     */
    private function checkHighErrorRate(): void
    {
        $errorRate = $this->metrics->getErrorRate();
        $threshold = 0.1; // 10%

        if ($errorRate > $threshold) {
            $this->sendAlert('high_error_rate', [
                'error_rate' => $errorRate * 100,
                'threshold' => $threshold * 100,
                'message' => "Taxa de erro alta: {$errorRate}% (threshold: {$threshold}%)",
            ]);
        }
    }

    /**
     * Verifica se tempo de resposta da IA está alto
     */
    private function checkAIResponseTime(): void
    {
        $avgResponseTime = $this->metrics->getAverageResponseTime();
        $threshold = 5000; // 5 segundos

        if ($avgResponseTime > $threshold) {
            $this->sendAlert('high_ai_response_time', [
                'response_time' => $avgResponseTime,
                'threshold' => $threshold,
                'message' => "Tempo de resposta da IA alto: {$avgResponseTime}ms (threshold: {$threshold}ms)",
            ]);
        }
    }

    /**
     * Verifica tamanho da fila
     */
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

    /**
     * Envia alerta
     */
    private function sendAlert(string $type, array $data): void
    {
        // Log do alerta
        Log::warning("Alert: {$type}", $data);

        // Registra no audit log
        AuditLog::create([
            'user_id' => null, // Sistema
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

        // Envia email se configurado
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

    /**
     * Verifica se há orçamentos excedidos e envia alertas
     */
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

            if ($exceededBudgets->isNotEmpty()) {
                foreach ($exceededBudgets as $budget) {
                    $this->sendBudgetAlert($user, $budget);
                }
            }
        }
    }

    /**
     * Envia alerta de orçamento excedido
     */
    private function sendBudgetAlert(\App\Models\User $user, \App\Models\Budget $budget): void
    {
        $startOfMonth = now()->startOfMonth();
        $endOfMonth = now()->endOfMonth();
        
        $spent = $user->transactions()
            ->where('category_id', $budget->category_id)
            ->where('type', 'expense')
            ->whereBetween('date', [$startOfMonth, $endOfMonth])
            ->sum('amount');

        $exceededBy = $spent - $budget->amount;

        // Envia via WhatsApp se o usuário tiver contato
        $contact = $user->whatsAppContacts()->first();
        if ($contact) {
            try {
                $message = "⚠️ *Alerta de Orçamento*\n\n";
                $message .= "Você excedeu o orçamento de *{$budget->category->name}*\n\n";
                $message .= "💰 Orçado: R$ " . number_format($budget->amount, 2, ',', '.') . "\n";
                $message .= "💸 Gasto: R$ " . number_format($spent, 2, ',', '.') . "\n";
                $message .= "📊 Excedido por: R$ " . number_format($exceededBy, 2, ',', '.') . "\n";

                $this->baileysService->sendTextMessage(
                    $contact->phone_number,
                    $message
                );
            } catch (\Exception $e) {
                Log::error("Erro ao enviar alerta de orçamento via WhatsApp: {$e->getMessage()}");
            }
        }
    }
}
