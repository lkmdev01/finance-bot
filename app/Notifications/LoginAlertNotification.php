<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LoginAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
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
        return (new MailMessage)
            ->subject('Novo login na sua conta InovaFinance')
            ->greeting("Oi, {$notifiable->name}.")
            ->line('Detectamos um novo acesso na sua conta.')
            ->line('IP: '.($this->ipAddress ?: 'nao informado'))
            ->line('Dispositivo: '.str($this->userAgent ?: 'nao informado')->limit(140))
            ->line('Se foi voce, nenhuma acao e necessaria.')
            ->action('Abrir minha conta', route('dashboard'))
            ->line('Se voce nao reconhece este acesso, altere sua senha imediatamente.');
    }
}
