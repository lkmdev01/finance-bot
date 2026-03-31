<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\RecurringTransaction;
use App\Models\SavingsGoalAlert;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProactiveNotificationService
{
    public function __construct(
        private readonly BaileysService $baileysService
    ) {}

    public function sendProactiveNotifications(): void
    {
        $this->notifyExceededBudgets();
        $this->notifySavingsGoalAlerts();
        $this->notifyRecurringTransactions();
    }

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

            if ($exceededBudgets->isEmpty()) {
                continue;
            }

            $contact = $user->whatsAppContacts()->first();
            if (! $contact) {
                continue;
            }

            foreach ($exceededBudgets as $budget) {
                $this->sendBudgetExceededNotification($contact->phone_number, $budget);
            }
        }
    }

    private function sendBudgetExceededNotification(string $phoneNumber, Budget $budget): void
    {
        if ($this->budgetAlertAlreadySent($budget->user_id, $budget->id)) {
            return;
        }

        $startOfMonth = now()->startOfMonth();
        $endOfMonth = now()->endOfMonth();

        $spent = $budget->user->transactions()
            ->where('category_id', $budget->category_id)
            ->where('type', 'expense')
            ->whereBetween('date', [$startOfMonth, $endOfMonth])
            ->sum('amount');

        $exceededBy = $spent - $budget->amount;
        $percentage = $budget->amount > 0 ? ($spent / $budget->amount) * 100 : 0;

        $message = "Alerta de Orcamento\n\n";
        $message .= "Voce excedeu o orcamento de {$budget->category->name}\n\n";
        $message .= 'Orcado: R$ '.number_format($budget->amount, 2, ',', '.')."\n";
        $message .= 'Gasto: R$ '.number_format($spent, 2, ',', '.')."\n";
        $message .= 'Excedido por: R$ '.number_format($exceededBy, 2, ',', '.')."\n";
        $message .= number_format($percentage, 1)."% do orcamento usado\n\n";
        $message .= 'Considere revisar os gastos dessa categoria.';

        try {
            $this->baileysService->sendTextMessage($phoneNumber, $message);
            $this->markBudgetAlertAsSent($budget->user_id, $budget->id);
            Log::info('Notificacao proativa de orcamento excedido enviada', [
                'user_id' => $budget->user_id,
                'budget_id' => $budget->id,
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao enviar notificacao de orcamento excedido', [
                'user_id' => $budget->user_id,
                'budget_id' => $budget->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

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

            if (! $contact) {
                continue;
            }

            $currentAmount = $goal->deposits()->sum('amount');
            $remaining = $goal->target_amount - $currentAmount;
            $progress = $goal->target_amount > 0
                ? ($currentAmount / $goal->target_amount) * 100
                : 0;

            $message = "Meta de Poupanca\n\n";
            $message .= "Meta: {$goal->name}\n\n";
            $message .= 'Valor atual: R$ '.number_format($currentAmount, 2, ',', '.')."\n";
            $message .= 'Meta: R$ '.number_format($goal->target_amount, 2, ',', '.')."\n";
            $message .= 'Progresso: '.number_format($progress, 1)."%\n";

            if ($remaining > 0) {
                $message .= 'Faltam: R$ '.number_format($remaining, 2, ',', '.')."\n";
            }

            if ($goal->target_date) {
                $daysLeft = now()->diffInDays($goal->target_date, false);
                if ($daysLeft > 0) {
                    $message .= "Faltam {$daysLeft} dias\n";
                } elseif ($daysLeft === 0) {
                    $message .= "Prazo e hoje\n";
                } else {
                    $message .= 'Prazo expirado ha '.abs($daysLeft)." dias\n";
                }
            }

            try {
                $this->baileysService->sendTextMessage($contact->phone_number, $message);
                $alert->update(['notified' => true]);
                Log::info('Notificacao proativa de meta de poupanca enviada', [
                    'user_id' => $user->id,
                    'alert_id' => $alert->id,
                ]);
            } catch (\Exception $e) {
                Log::error('Erro ao enviar notificacao de meta de poupanca', [
                    'user_id' => $user->id,
                    'alert_id' => $alert->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function notifyRecurringTransactions(): void
    {
        $recurringTransactions = RecurringTransaction::where('is_active', true)
            ->with('user')
            ->get();

        foreach ($recurringTransactions as $recurring) {
            $nextDate = $recurring->getNextOccurrenceDate();

            if (! $nextDate || (! $nextDate->isToday() && ! $nextDate->isTomorrow())) {
                continue;
            }

            $user = $recurring->user;
            $contact = $user->whatsAppContacts()->first();

            if (! $contact) {
                continue;
            }

            $daysUntil = now()->diffInDays($nextDate, false);
            $daysText = $daysUntil === 0 ? 'hoje' : ($daysUntil === 1 ? 'amanha' : "em {$daysUntil} dias");
            $categoryName = $recurring->category?->name ?? 'Sem categoria';

            $message = "Lembrete de Transacao Recorrente\n\n";
            $message .= "Voce tem uma transacao recorrente agendada para {$daysText}:\n\n";
            $message .= 'Valor: R$ '.number_format($recurring->amount, 2, ',', '.')."\n";
            $message .= "Descricao: {$recurring->description}\n";
            $message .= "Categoria: {$categoryName}\n";
            $message .= "Frequencia: {$recurring->frequency}\n";
            $message .= 'Data: '.$nextDate->format('d/m/Y')."\n\n";
            $message .= 'Essa transacao sera criada automaticamente.';

            try {
                $this->baileysService->sendTextMessage($contact->phone_number, $message);
                Log::info('Notificacao proativa de transacao recorrente enviada', [
                    'user_id' => $user->id,
                    'recurring_transaction_id' => $recurring->id,
                ]);
            } catch (\Exception $e) {
                Log::error('Erro ao enviar notificacao de transacao recorrente', [
                    'user_id' => $user->id,
                    'recurring_transaction_id' => $recurring->id,
                    'error' => $e->getMessage(),
                ]);
            }
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