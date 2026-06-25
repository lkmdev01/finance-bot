<?php

namespace App\Notifications;

use App\Models\AbacatePaySubscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BillingSubscriptionCancelledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public ?AbacatePaySubscription $subscription = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $planName = $this->planName($this->subscription?->plan_code ?? $notifiable->billing_plan_code);

        return (new MailMessage)
            ->subject('Sua assinatura InovaFinance foi cancelada')
            ->greeting("Oi, {$notifiable->name}.")
            ->line("Confirmamos o cancelamento do plano {$planName}.")
            ->line('Seu acesso premium foi encerrado conforme a politica de cancelamento atual.')
            ->action('Ver planos', route('billing.plans'))
            ->line('Se isso foi um engano, voce pode reativar seu plano quando quiser.');
    }

    private function planName(?string $planCode): string
    {
        return config("billing.plans.{$planCode}.name") ?: 'InovaFinance';
    }
}
