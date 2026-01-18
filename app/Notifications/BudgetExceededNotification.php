<?php

namespace App\Notifications;

use App\Models\Budget;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BudgetExceededNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public Budget $budget
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Orçamento Excedido')
            ->line("O orçamento da categoria {$this->budget->category->name} foi excedido.")
            ->line("Orçado: R$ " . number_format($this->budget->amount, 2, ',', '.'))
            ->line("Gasto: R$ " . number_format($this->budget->spent, 2, ',', '.'))
            ->action('Ver Orçamentos', route('budgets.index'));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'budget_exceeded',
            'budget_id' => $this->budget->id,
            'category_name' => $this->budget->category->name,
            'budgeted_amount' => $this->budget->amount,
            'spent_amount' => $this->budget->spent,
            'message' => "O orçamento da categoria {$this->budget->category->name} foi excedido em R$ " . number_format($this->budget->spent - $this->budget->amount, 2, ',', '.'),
        ];
    }
}
