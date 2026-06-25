<?php

namespace App\Notifications;

use App\Models\AbacatePaySubscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BillingPaymentFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public ?AbacatePaySubscription $subscription = null,
        public ?string $reason = null,
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
        $planName = $this->planName($this->subscription?->plan_code);

        return (new MailMessage)
            ->subject('Nao conseguimos confirmar seu pagamento no InovaFinance')
            ->greeting("Oi, {$notifiable->name}.")
            ->line("Nao conseguimos confirmar o pagamento do plano {$planName}.")
            ->line('Seu acesso pode continuar ativo ate a data ja contratada, mas a renovacao precisa ser regularizada para evitar interrupcoes.')
            ->when(filled($this->reason), fn (MailMessage $message) => $message->line("Motivo informado: {$this->reason}"))
            ->action('Ver minha assinatura', route('billing.plans'))
            ->line('Se voce ja fez o pagamento, aguarde alguns minutos ou fale com nosso suporte.');
    }

    private function planName(?string $planCode): string
    {
        return config("billing.plans.{$planCode}.name") ?: 'InovaFinance';
    }
}
