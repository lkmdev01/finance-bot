<?php

namespace App\Console\Commands;

use App\Models\ExpensePlan;
use App\Services\WebhookService;
use Illuminate\Console\Command;

class UpdateExpensePlans extends Command
{
    protected $signature = 'expense-plans:update';

    protected $description = 'Atualiza valores gastos nos planos de despesas';

    public function handle(): int
    {
        $this->info('Atualizando planos de despesas...');

        $plans = ExpensePlan::with('user')
            ->where('is_active', true)
            ->get();

        $updatedCount = 0;

        foreach ($plans as $plan) {
            $wasExceeded = $plan->is_exceeded;
            $plan->updateSpentAmount();
            $updatedCount++;

            // Verificar se acabou de exceder
            if ($plan->is_exceeded && ! $wasExceeded) {
                $this->warn("Plano excedido: {$plan->name} - Usuário: {$plan->user->name}");
                
                // Disparar webhook
                app(WebhookService::class)->dispatch(
                    'expense_plan.exceeded',
                    $plan->user,
                    [
                        'expense_plan_id' => $plan->id,
                        'plan_name' => $plan->name,
                        'planned_amount' => $plan->planned_amount,
                        'spent_amount' => $plan->spent_amount,
                    ]
                );
            }
        }

        $this->info("Atualização concluída. {$updatedCount} plano(s) atualizado(s).");

        return Command::SUCCESS;
    }
}
