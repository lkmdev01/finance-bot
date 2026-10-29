<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class AiTokenUsageSpikeNotification extends Notification
{
    public function __construct(
        public readonly int $tokensInHour,
        public readonly int $threshold,
        public readonly string $hourStart,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'ai_token_usage_spike',
            'tokens_in_hour' => $this->tokensInHour,
            'threshold' => $this->threshold,
            'hour_start' => $this->hourStart,
            'message' => 'Seu assistente usou '.number_format($this->tokensInHour, 0, ',', '.').
                ' tokens de IA nesta hora. Acompanhe o consumo no painel de uso de IA.',
            'url' => route('ai-usage.index'),
        ];
    }

    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
