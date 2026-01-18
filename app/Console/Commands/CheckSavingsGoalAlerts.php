<?php

namespace App\Console\Commands;

use App\Models\SavingsGoalAlert;
use App\Notifications\SavingsGoalAlertNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class CheckSavingsGoalAlerts extends Command
{
    protected $signature = 'savings-goals:check-alerts';

    protected $description = 'Verifica alertas de metas de economia e envia notificações';

    public function handle(): int
    {
        $this->info('Verificando alertas de metas de economia...');

        $alerts = SavingsGoalAlert::with(['user', 'savingsGoal'])
            ->where('is_active', true)
            ->get();

        $triggeredCount = 0;

        foreach ($alerts as $alert) {
            if ($alert->shouldTrigger()) {
                $alert->user->notify(new SavingsGoalAlertNotification($alert));
                
                // Disparar webhook baseado no tipo de alerta
                $event = match ($alert->type) {
                    'milestone' => 'savings_goal.milestone',
                    'deadline' => 'savings_goal.deadline',
                    'low_progress' => 'savings_goal.low_progress',
                    default => null,
                };

                if ($event) {
                    app(\App\Services\WebhookService::class)->dispatch(
                        $event,
                        $alert->user,
                        [
                            'savings_goal_id' => $alert->savings_goal_id,
                            'goal_name' => $alert->savingsGoal->name,
                            'alert_type' => $alert->type,
                            'progress_percentage' => $alert->savingsGoal->progress_percentage,
                            'current_amount' => $alert->savingsGoal->current_amount,
                            'target_amount' => $alert->savingsGoal->target_amount,
                        ]
                    );
                }
                
                $alert->update(['last_triggered_at' => now()]);
                $triggeredCount++;

                $this->line("Alerta disparado: {$alert->savingsGoal->name} - Usuário: {$alert->user->name}");
            }
        }

        $this->info("Verificação concluída. {$triggeredCount} alerta(s) disparado(s).");

        return Command::SUCCESS;
    }
}
