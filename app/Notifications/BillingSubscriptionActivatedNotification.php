<?php

namespace App\Notifications;

use App\Models\AbacatePaySubscription;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BillingSubscriptionActivatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public AbacatePaySubscription $subscription,
        public ?CarbonInterface $accessEndsAt = null,
        public bool $renewed = false,
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
        $planName = $this->planName($this->subscription->plan_code);
        $subject = $this->renewed
            ? 'Sua assinatura InovaFinance foi renovada'
            : 'Sua assinatura InovaFinance esta ativa';

        return (new MailMessage)
            ->subject($subject)
            ->greeting("Oi, {$notifiable->name}.")
            ->line($this->renewed
                ? "Confirmamos a renovacao do seu plano {$planName}."
                : "Confirmamos o pagamento e seu plano {$planName} ja esta ativo.")
            ->when($this->accessEndsAt, fn (MailMessage $message) => $message->line('Proxima renovacao/acesso valido ate: '.$this->accessEndsAt->format('d/m/Y').'.'))
            ->line('Agora voce pode continuar usando os recursos premium do InovaFinance.')
            ->action('Acessar minha conta', route('dashboard'))
            ->line('Obrigado por confiar no InovaFinance para organizar sua vida financeira.');
    }

    private function planName(?string $planCode): string
    {
        return config("billing.plans.{$planCode}.name") ?: 'InovaFinance';
    }
}
