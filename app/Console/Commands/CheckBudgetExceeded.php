<?php

namespace App\Console\Commands;

use App\Models\Budget;
use App\Notifications\BudgetExceededNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class CheckBudgetExceeded extends Command
{
    protected $signature = 'budgets:check-exceeded';

    protected $description = 'Verifica orçamentos excedidos e envia notificações';

    public function handle(): int
    {
        $this->info('Verificando orçamentos excedidos...');

        $budgets = Budget::with(['user', 'category'])
            ->whereHas('user')
            ->get();

        $exceededCount = 0;

        foreach ($budgets as $budget) {
            if ($budget->spent > $budget->amount) {
                // Verifica se já foi notificado hoje
                $alreadyNotified = $budget->user->notifications()
                    ->where('type', BudgetExceededNotification::class)
                    ->where('data->budget_id', $budget->id)
                    ->whereDate('created_at', today())
                    ->exists();

                if (!$alreadyNotified) {
                    $budget->user->notify(new BudgetExceededNotification($budget));
                    
                    // Disparar webhook
                    app(\App\Services\WebhookService::class)->dispatch(
                        'budget.exceeded',
                        $budget->user,
                        [
                            'budget_id' => $budget->id,
                            'category_name' => $budget->category->name,
                            'budgeted_amount' => $budget->amount,
                            'spent_amount' => $budget->spent,
                        ]
                    );
                    
                    $exceededCount++;
                    $this->line("Orçamento excedido notificado: {$budget->category->name} - Usuário: {$budget->user->name}");
                }
            }
        }

        $this->info("Verificação concluída. {$exceededCount} notificação(ões) enviada(s).");

        return Command::SUCCESS;
    }
}
