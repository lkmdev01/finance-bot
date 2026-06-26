<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

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
            ->subject('Sua senha do InovaFinance foi alterada')
            ->greeting("Oi, {$notifiable->name}.")
            ->line('Confirmamos que a senha da sua conta foi alterada.')
            ->line('Se foi voce, esta tudo certo.')
            ->action('Acessar conta', route('dashboard'))
            ->line('Se voce nao fez essa alteracao, solicite uma nova senha imediatamente e fale com o suporte.');
    }
}
