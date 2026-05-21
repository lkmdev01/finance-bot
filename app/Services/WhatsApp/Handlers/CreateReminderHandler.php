<?php

namespace App\Services\WhatsApp\Handlers;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Reminder;
use App\Models\User;
use App\Models\WhatsAppContact;
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
            'frequency' => ['required', 'in:once,monthly,yearly'],
            'timezone' => ['required', 'string', 'max:60'],
            'next_trigger_at' => ['required', 'date'],
            'day_of_month' => ['nullable', 'integer', 'min:1', 'max:31'],
            'month_of_year' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);

        if ($validation->fails()) {
            $this->sendErrorMessage($job, "Nao consegui criar esse lembrete.\n\nTente assim:\n* dia 10 desse mes me lembra de pagar Joao\n* me lembra de dar parabens para Maria dia 10/06");
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
            'day_of_month' => isset($data['day_of_month']) ? (int) $data['day_of_month'] : null,
            'month_of_year' => isset($data['month_of_year']) ? (int) $data['month_of_year'] : null,
            'metadata' => $data['metadata'] ?? [],
        ];
    }

    private function buildSuccessMessage(Reminder $reminder): string
    {
        return match ($reminder->frequency) {
            'monthly' => sprintf(
                'Lembrete mensal criado para %s, todo dia %d.',
                $reminder->title,
                (int) $reminder->day_of_month
            ),
            'yearly' => sprintf(
                'Lembrete anual criado para %s, todo dia %02d/%02d.',
                $reminder->title,
                (int) $reminder->day_of_month,
                (int) $reminder->month_of_year
            ),
            default => sprintf(
                'Lembrete criado para %s em %s.',
                $reminder->title,
                $reminder->next_trigger_at?->format('d/m/Y')
            ),
        };
    }
}
