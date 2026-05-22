<?php

namespace App\Services\WhatsApp\Handlers;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Reminder;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\WhatsApp\ReminderMessageTemplateFactory;
use Illuminate\Support\Facades\Validator;

class CreateReminderHandler extends BaseHandler
{
    public function canHandle(?string $action): bool
    {
        return $action === 'create_reminder';
    }

    public function handle(?string $action, array &$result, User $user, WhatsAppContact $contact, ProcessWhatsAppMessage $job): bool
    {
        $data = $this->normalizeData($result['reminder_data'] ?? []);

        $validation = Validator::make($data, [
            'title' => ['required', 'string', 'min:3', 'max:120'],
            'message' => ['required', 'string', 'min:3', 'max:255'],
            'frequency' => ['required', 'in:once,daily,weekly,monthly,yearly'],
            'timezone' => ['required', 'string', 'max:60'],
            'next_trigger_at' => ['required', 'date'],
            'day_of_week' => ['nullable', 'integer', 'min:0', 'max:6'],
            'day_of_month' => ['nullable', 'integer', 'min:1', 'max:31'],
            'month_of_year' => ['nullable', 'integer', 'min:1', 'max:12'],
            'trigger_time' => ['nullable', 'regex:/^\d{2}:\d{2}:\d{2}$/'],
        ]);

        $validation->after(function ($validator) use ($data) {
            if ($data['frequency'] === 'weekly' && $data['day_of_week'] === null) {
                $validator->errors()->add('day_of_week', 'Informe o dia da semana para lembrete semanal.');
            }

            if ($data['frequency'] === 'monthly' && $data['day_of_month'] === null) {
                $validator->errors()->add('day_of_month', 'Informe o dia do mes para lembrete mensal.');
            }

            if ($data['frequency'] === 'yearly' && ($data['day_of_month'] === null || $data['month_of_year'] === null)) {
                $validator->errors()->add('yearly_schedule', 'Informe dia e mes para lembrete anual.');
            }
        });

        if ($validation->fails()) {
            $this->sendErrorMessage($job, "Nao consegui criar esse lembrete.\n\nTente assim:\n* me lembra no dia 5 do mes que vem de pagar Joao\n* me lembra de dar parabens para Maria anualmente dia 10 do mes 6\n* me lembra todo dia as 08:30 de tomar agua");
            return true;
        }

        $reminder = Reminder::query()->create(array_merge($data, [
            'user_id' => $user->id,
            'is_active' => true,
        ]));

        $result['_conversation_metadata'] = array_merge($result['_conversation_metadata'] ?? [], [
            'reply_kind' => 'action',
            'entities' => [
                'topic' => 'reminders',
                'reminder_id' => $reminder->id,
                'reminder_title' => $reminder->title,
                'frequency' => $reminder->frequency,
            ],
        ]);

        $this->sendResponse($job, $this->buildSuccessMessage($reminder), $user);

        return true;
    }

    private function normalizeData(array $data): array
    {
        return [
            'title' => trim((string) ($data['title'] ?? '')),
            'message' => trim((string) ($data['message'] ?? '')),
            'frequency' => (string) ($data['frequency'] ?? ''),
            'timezone' => (string) ($data['timezone'] ?? config('app.timezone')),
            'next_trigger_at' => (string) ($data['next_trigger_at'] ?? ''),
            'day_of_week' => isset($data['day_of_week']) ? (int) $data['day_of_week'] : null,
            'day_of_month' => isset($data['day_of_month']) ? (int) $data['day_of_month'] : null,
            'month_of_year' => isset($data['month_of_year']) ? (int) $data['month_of_year'] : null,
            'trigger_time' => isset($data['trigger_time']) ? (string) $data['trigger_time'] : '09:00:00',
            'metadata' => $data['metadata'] ?? [],
        ];
    }

    private function buildSuccessMessage(Reminder $reminder): string
    {
        $time = $reminder->trigger_time ? substr($reminder->trigger_time, 0, 5) : '09:00';
        $templateType = ReminderMessageTemplateFactory::detect($reminder->title, $reminder->message);

        return match ($reminder->frequency) {
            'daily' => sprintf(
                'Lembrete diario criado! 📅\n\n%s',
                ReminderMessageTemplateFactory::buildFriendlyMessage($reminder->title, 'daily', $templateType)
            ),
            'weekly' => sprintf(
                'Lembrete semanal criado! 📅\n\n%s',
                ReminderMessageTemplateFactory::buildFriendlyMessage($reminder->title, 'weekly', $templateType)
            ),
            'monthly' => sprintf(
                'Lembrete mensal criado! 📅\n\n%s',
                ReminderMessageTemplateFactory::buildFriendlyMessage($reminder->title, 'monthly', $templateType)
            ),
            'yearly' => sprintf(
                'Lembrete anual criado! 📅\n\n%s',
                ReminderMessageTemplateFactory::buildFriendlyMessage($reminder->title, 'yearly', $templateType)
            ),
            default => sprintf(
                'Lembrete criado para %s em %s as %s. ✅',
                $reminder->title,
                $reminder->next_trigger_at?->format('d/m/Y'),
                $time
            ),
        };
    }

    private function weekdayLabel(int $dayOfWeek): string
    {
        return [
            0 => 'domingo',
            1 => 'segunda-feira',
            2 => 'terca-feira',
            3 => 'quarta-feira',
            4 => 'quinta-feira',
            5 => 'sexta-feira',
            6 => 'sabado',
        ][$dayOfWeek] ?? 'dia da semana';
    }
}
