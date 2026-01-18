<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\SavingsGoalAlert;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class ProactiveNotificationService
{
    public function __construct(
        private readonly BaileysService $baileysService
    ) {}

    /**
     * Envia notificações proativas para usuários
     */
    public function sendProactiveNotifications(): void
    {
        $this->notifyExceededBudgets();
        $this->notifySavingsGoalAlerts();
        $this->notifyRecurringTransactions();
    }

    /**
     * Notifica sobre orçamentos excedidos
     */
    private function notifyExceededBudgets(): void
    {
        $users = User::whereHas('budgets')->get();

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
                $contact = $user->whatsAppContacts()->first();
                if ($contact) {
                    foreach ($exceededBudgets as $budget) {
                        $this->sendBudgetExceededNotification($contact, $budget);
                    }
                }
            }
        }
    }

    /**
     * Envia notificação de orçamento excedido
     */
    private function sendBudgetExceededNotification($contact, Budget $budget): void
    {
        $startOfMonth = now()->startOfMonth();
        $endOfMonth = now()->endOfMonth();

        $spent = $budget->user->transactions()
            ->where('category_id', $budget->category_id)
            ->where('type', 'expense')
            ->whereBetween('date', [$startOfMonth, $endOfMonth])
            ->sum('amount');

        $exceededBy = $spent - $budget->amount;
        $percentage = ($spent / $budget->amount) * 100;

        $message = "⚠️ *Alerta de Orçamento*\n\n";
        $message .= "Você excedeu o orçamento de *{$budget->category->name}*\n\n";
        $message .= '💰 Orçado: R$ '.number_format($budget->amount, 2, ',', '.')."\n";
        $message .= '💸 Gasto: R$ '.number_format($spent, 2, ',', '.')."\n";
        $message .= '📊 Excedido por: R$ '.number_format($exceededBy, 2, ',', '.')."\n";
        $message .= '📈 '.number_format($percentage, 1)."% do orçamento usado\n\n";
        $message .= '💡 Considere revisar seus gastos nesta categoria.';

        try {
            $this->baileysService->sendTextMessage($contact->phone_number, $message);
            Log::info('Notificação proativa de orçamento excedido enviada', [
                'user_id' => $budget->user_id,
                'budget_id' => $budget->id,
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao enviar notificação de orçamento excedido', [
                'user_id' => $budget->user_id,
                'budget_id' => $budget->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notifica sobre alertas de metas de poupança
     */
    private function notifySavingsGoalAlerts(): void
    {
        $alerts = SavingsGoalAlert::where('notified', false)
            ->where('alert_date', '<=', now())
            ->with(['savingsGoal.user'])
            ->get();

        foreach ($alerts as $alert) {
            $goal = $alert->savingsGoal;
            $user = $goal->user;
            $contact = $user->whatsAppContacts()->first();

            if ($contact) {
                $currentAmount = $goal->deposits()->sum('amount');
                $remaining = $goal->target_amount - $currentAmount;
                $progress = $goal->target_amount > 0
                    ? ($currentAmount / $goal->target_amount) * 100
                    : 0;

                $message = "🎯 *Alerta de Meta de Poupança*\n\n";
                $message .= "Meta: *{$goal->name}*\n\n";
                $message .= '💰 Valor atual: R$ '.number_format($currentAmount, 2, ',', '.')."\n";
                $message .= '🎯 Meta: R$ '.number_format($goal->target_amount, 2, ',', '.')."\n";
                $message .= '📊 Progresso: '.number_format($progress, 1)."%\n";

                if ($remaining > 0) {
                    $message .= '💵 Faltam: R$ '.number_format($remaining, 2, ',', '.')."\n";
                }

                if ($goal->target_date) {
                    $daysLeft = now()->diffInDays($goal->target_date, false);
                    if ($daysLeft > 0) {
                        $message .= "📅 Faltam {$daysLeft} dias\n";
                    } elseif ($daysLeft === 0) {
                        $message .= "📅 Prazo é hoje!\n";
                    } else {
                        $message .= '📅 Prazo expirado há '.abs($daysLeft)." dias\n";
                    }
                }

                try {
                    $this->baileysService->sendTextMessage($contact->phone_number, $message);
                    $alert->update(['notified' => true]);
                    Log::info('Notificação proativa de meta de poupança enviada', [
                        'user_id' => $user->id,
                        'alert_id' => $alert->id,
                    ]);
                } catch (\Exception $e) {
                    Log::error('Erro ao enviar notificação de meta de poupança', [
                        'user_id' => $user->id,
                        'alert_id' => $alert->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * Notifica sobre transações recorrentes próximas
     */
    private function notifyRecurringTransactions(): void
    {
        $recurringTransactions = \App\Models\RecurringTransaction::where('is_active', true)
            ->with('user')
            ->get();

        foreach ($recurringTransactions as $recurring) {
            $nextDate = $recurring->getNextOccurrenceDate();

            if (! $nextDate) {
                continue;
            }

            // Notifica hoje ou amanhã
            if ($nextDate->isToday() || $nextDate->isTomorrow()) {
                $user = $recurring->user;
                $contact = $user->whatsAppContacts()->first();

                if ($contact) {
                    $daysUntil = now()->diffInDays($nextDate, false);
                    $daysText = $daysUntil === 0 ? 'hoje' : ($daysUntil === 1 ? 'amanhã' : "em {$daysUntil} dias");

                    $message = "📅 *Lembrete de Transação Recorrente*\n\n";
                    $message .= "Você tem uma transação recorrente agendada para {$daysText}:\n\n";
                    $message .= '💰 Valor: R$ '.number_format($recurring->amount, 2, ',', '.')."\n";
                    $message .= "📝 Descrição: {$recurring->description}\n";
                    $categoryName = $recurring->category?->name ?? 'Sem categoria';
                    $message .= "📁 Categoria: {$categoryName}\n";
                    $message .= "🔄 Frequência: {$recurring->frequency}\n";
                    $message .= "📅 Data: {$nextDate->format('d/m/Y')}\n\n";
                    $message .= '💡 Esta transação será criada automaticamente.';

                    try {
                        $this->baileysService->sendTextMessage($contact->phone_number, $message);
                        Log::info('Notificação proativa de transação recorrente enviada', [
                            'user_id' => $user->id,
                            'recurring_transaction_id' => $recurring->id,
                        ]);
                    } catch (\Exception $e) {
                        Log::error('Erro ao enviar notificação de transação recorrente', [
                            'user_id' => $user->id,
                            'recurring_transaction_id' => $recurring->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }
    }
}
