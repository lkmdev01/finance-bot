<?php

namespace App\Services\WhatsApp;

use App\Models\Reminder;
use App\Models\User;

class ReminderConversationService
{
    public function buildReply(User $user, string $message, array $state): array
    {
        $normalized = app(IncomingMessageNormalizer::class)->normalize($message);

        if (($followUpReply = $this->buildFollowUpReply($user, $normalized, $state)) !== null) {
            return $followUpReply;
        }

        $reminders = $user->reminders()
            ->where('is_active', true)
            ->orderBy('next_trigger_at')
            ->limit(10)
            ->get();

        if ($reminders->isEmpty()) {
            return [
                'reply' => 'Voce ainda nao tem lembretes ativos. Se quiser, posso criar um para voce.',
                'entities' => ['topic' => 'reminders'],
            ];
        }

        $lines = $reminders->map(function ($reminder) {
            $nextDate = $reminder->next_trigger_at?->format('d/m/Y') ?? 'sem data';
            $time = $reminder->trigger_time ? substr($reminder->trigger_time, 0, 5) : '09:00';
            $frequency = match ($reminder->frequency) {
                'daily' => 'diario',
                'weekly' => 'semanal',
                'monthly' => 'mensal',
                'yearly' => 'anual',
                default => 'pontual',
            };

            return sprintf('- %s: %s, proximo em %s as %s', $reminder->title, $frequency, $nextDate, $time);
        })->implode("\n");

        return [
            'reply' => "Seus lembretes ativos:\n{$lines}",
            'entities' => [
                'topic' => 'reminders',
                'reminder_id' => $reminders->first()?->id,
                'reminder_title' => $reminders->first()?->title,
                'recent_reminder_ids' => $reminders->pluck('id')->values()->all(),
                'reminder_result_count' => $reminders->count(),
            ],
        ];
    }

    private function buildFollowUpReply(User $user, string $normalized, array $state): ?array
    {
        if (($state['last_entities']['topic'] ?? null) !== 'reminders') {
            return null;
        }

        $recentReminder = $this->resolveRecentReminder($user, $state);
        $count = (int) ($state['last_entities']['reminder_result_count'] ?? 0);

        if ($this->containsAny($normalized, ['me mostra esse lembrete', 'me mostra ele', 'mostra esse lembrete', 'abre esse lembrete'])) {
            if (! $recentReminder) {
                return null;
            }

            $nextDate = $recentReminder->next_trigger_at?->format('d/m/Y') ?? 'sem data';
            $time = $recentReminder->trigger_time ? substr($recentReminder->trigger_time, 0, 5) : '09:00';

            return [
                'reply' => "Aqui esta o lembrete {$recentReminder->title}.\n\nProximo disparo: {$nextDate} as {$time}.",
                'entities' => [
                    'topic' => 'reminders',
                    'reminder_id' => $recentReminder->id,
                    'reminder_title' => $recentReminder->title,
                    'recent_reminder_ids' => $state['last_entities']['recent_reminder_ids'] ?? [],
                    'reminder_result_count' => max(1, $count),
                ],
            ];
        }

        if ($this->containsAny($normalized, ['so esse', 'só esse', 'apenas esse', 'so esse lembrete'])) {
            $reply = $count <= 1
                ? 'Por enquanto, sim. '
                : "Nao. Encontrei {$count} lembretes ativos nessa lista. ";
            $reply .= 'Esse e o mais proximo da lista atual.';

            return [
                'reply' => trim($reply),
                'entities' => [
                    'topic' => 'reminders',
                    'reminder_id' => $recentReminder?->id,
                    'reminder_title' => $recentReminder?->title,
                    'recent_reminder_ids' => $state['last_entities']['recent_reminder_ids'] ?? [],
                    'reminder_result_count' => max(1, $count),
                ],
            ];
        }

        if ($this->containsAny($normalized, ['tem mais lembrete', 'tem mais lembretes'])) {
            $reply = $count > 1
                ? "Sim. Eu encontrei {$count} lembretes ativos nessa lista."
                : 'Por enquanto, nao. So encontrei esse lembrete ativo nessa lista.';

            return [
                'reply' => $reply,
                'entities' => [
                    'topic' => 'reminders',
                    'reminder_id' => $recentReminder?->id,
                    'reminder_title' => $recentReminder?->title,
                    'recent_reminder_ids' => $state['last_entities']['recent_reminder_ids'] ?? [],
                    'reminder_result_count' => max(1, $count),
                ],
            ];
        }

        return null;
    }

    private function resolveRecentReminder(User $user, array $state): ?Reminder
    {
        $reminderId = (int) ($state['last_entities']['reminder_id'] ?? 0);
        if ($reminderId > 0) {
            return $user->reminders()->find($reminderId);
        }

        $recentIds = array_values(array_filter($state['last_entities']['recent_reminder_ids'] ?? [], fn ($id) => (int) $id > 0));
        if ($recentIds === []) {
            return null;
        }

        return $user->reminders()->find((int) $recentIds[0]);
    }

    private function containsAny(string $normalized, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($normalized, app(IncomingMessageNormalizer::class)->normalize($needle))) {
                return true;
            }
        }

        return false;
    }
}
