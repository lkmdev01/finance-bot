<?php

namespace App\Notifications;

use App\Models\SavingsGoalAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SavingsGoalAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public SavingsGoalAlert $alert
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $goal = $this->alert->savingsGoal;
        $message = $this->getMessage();

        return (new MailMessage)
            ->subject('Alerta de Meta de Economia')
            ->line($message)
            ->line("Meta: {$goal->name}")
            ->line("Progresso: {$goal->progress_percentage}%")
            ->line("Valor atual: R$ ".number_format($goal->current_amount, 2, ',', '.'))
            ->line("Meta: R$ ".number_format($goal->target_amount, 2, ',', '.'))
            ->action('Ver Metas', route('savings-goals.index'));
    }

    public function toArray(object $notifiable): array
    {
        $goal = $this->alert->savingsGoal;

        return [
            'type' => 'savings_goal_alert',
            'alert_id' => $this->alert->id,
            'savings_goal_id' => $goal->id,
            'goal_name' => $goal->name,
            'alert_type' => $this->alert->type,
            'message' => $this->getMessage(),
            'progress_percentage' => $goal->progress_percentage,
            'current_amount' => $goal->current_amount,
            'target_amount' => $goal->target_amount,
        ];
    }

    protected function getMessage(): string
    {
        $goal = $this->alert->savingsGoal;

        return match ($this->alert->type) {
            'milestone' => "Você atingiu {$this->alert->threshold_percentage}% da meta {$goal->name}!",
            'deadline' => "Atenção! Faltam {$this->alert->days_before_deadline} dias para o prazo da meta {$goal->name}.",
            'low_progress' => "Atenção! O progresso da meta {$goal->name} está abaixo do esperado.",
            default => "Alerta para a meta {$goal->name}.",
        };
    }
}
