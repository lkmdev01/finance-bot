<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BillingPlanExpiringNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public ?string $planCode,
        public CarbonInterface $accessEndsAt,
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
        $planName = config("billing.plans.{$this->planCode}.name") ?: 'InovaFinance';

        return (new MailMessage)
            ->subject('Seu plano InovaFinance esta proximo de vencer')
            ->greeting("Oi, {$notifiable->name}.")
            ->line("Seu acesso ao plano {$planName} esta previsto para encerrar em {$this->accessEndsAt->format('d/m/Y')}.")
            ->line('Se sua assinatura estiver ativa no cartao, a renovacao deve acontecer automaticamente.')
            ->action('Ver minha assinatura', route('billing.plans'))
            ->line('Se precisar de ajuda, responda este e-mail ou fale com o suporte.');
    }
}
