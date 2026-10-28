<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonInterface;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleCalendarService
{
    public function __construct(private readonly GoogleCalendarOAuthService $oauth) {}

    public function listEvents(User $user, CarbonInterface $from, CarbonInterface $to): array
    {
        if ($to->lessThanOrEqualTo($from)) {
            throw new RuntimeException('O fim do periodo deve ser posterior ao inicio.');
        }

        $response = $this->request($user)->get($this->calendarUrl($user), [
            'timeMin' => $from->toIso8601String(),
            'timeMax' => $to->toIso8601String(),
            'singleEvents' => 'true',
            'orderBy' => 'startTime',
            'maxResults' => 250,
        ]);

        $this->throwIfFailed($response->status(), $response->successful());

        return (array) $response->json('items', []);
    }

    public function hasConflict(User $user, CarbonInterface $start, CarbonInterface $end, string $timezone, ?string $excludeEventId = null): bool
    {
        if ($end->lessThanOrEqualTo($start)) {
            throw new RuntimeException('O fim do periodo deve ser posterior ao inicio.');
        }

        if ($excludeEventId !== null) {
            return collect($this->listEvents($user, $start, $end))->contains(function (array $event) use ($excludeEventId, $start, $end, $timezone): bool {
                if (($event['id'] ?? null) === $excludeEventId || ($event['transparency'] ?? null) === 'transparent') {
                    return false;
                }

                $eventStart = $event['start']['dateTime'] ?? $event['start']['date'] ?? null;
                $eventEnd = $event['end']['dateTime'] ?? $event['end']['date'] ?? null;
                if (! $eventStart || ! $eventEnd) {
                    return false;
                }

                $busyStart = CarbonImmutable::parse($eventStart, $timezone);
                $busyEnd = CarbonImmutable::parse($eventEnd, $timezone);

                return $busyStart->lessThan($end) && $busyEnd->greaterThan($start);
            });
        }

        $response = $this->request($user)->post('https://www.googleapis.com/calendar/v3/freeBusy', [
            'timeMin' => $start->toIso8601String(),
            'timeMax' => $end->toIso8601String(),
            'timeZone' => $timezone,
            'items' => [['id' => (string) ($user->googleCalendarConnection?->calendar_id ?: 'primary')]],
        ]);

        $this->throwIfFailed($response->status(), $response->successful());
        $calendarId = (string) ($user->googleCalendarConnection?->calendar_id ?: 'primary');
        $calendar = (array) $response->json('calendars', []);
        $calendar = $calendar[$calendarId] ?? null;
        if (! is_array($calendar) || ! empty($calendar['errors'])) {
            throw new RuntimeException('O Google Calendar não confirmou a disponibilidade do horário.');
        }

        return ! empty($calendar['busy']);
    }

    public function createEvent(User $user, array $event, string $idempotencyKey, bool $createMeetLink = false): array
    {
        if (blank($idempotencyKey) || strlen($idempotencyKey) > 255) {
            throw new RuntimeException('Chave de idempotencia invalida para criar o evento.');
        }

        $event = $this->validatedEvent($event, true);
        $eventId = 'if'.substr(hash('sha256', $idempotencyKey), 0, 40);
        $event['id'] = $eventId;
        if ($createMeetLink) {
            $event['conferenceData'] = [
                'createRequest' => [
                    'requestId' => $eventId,
                    'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
                ],
            ];
        }

        $request = $this->request($user)->withQueryParameters([
            'sendUpdates' => 'all',
            'conferenceDataVersion' => $createMeetLink ? 1 : 0,
        ]);
        $response = $request->post($this->calendarUrl($user), $event);
        if ($response->status() === 409) {
            $existing = $this->request($user)->get($this->calendarUrl($user).'/'.$eventId);
            if ($existing->successful()) {
                return (array) $existing->json();
            }
        }

        $this->throwIfFailed($response->status(), $response->successful());

        return (array) $response->json();
    }

    /** Validate the event payload before asking the user to confirm its creation. */
    public function validateEventForConfirmation(array $event): array
    {
        $payload = [
            'summary' => $event['summary'] ?? null,
            'start' => [
                'dateTime' => $event['start_at'] ?? null,
                'timeZone' => $event['time_zone'] ?? config('app.timezone', 'UTC'),
            ],
            'end' => [
                'dateTime' => $event['end_at'] ?? null,
                'timeZone' => $event['time_zone'] ?? config('app.timezone', 'UTC'),
            ],
            'attendees' => array_map(static fn (string $email): array => ['email' => $email], $event['attendees'] ?? []),
        ];

        return $this->validatedEvent($payload, true);
    }

    public function updateEvent(User $user, string $eventId, array $changes): array
    {
        $changes = $this->validatedEvent($changes, false);
        $response = $this->request($user)->withQueryParameters([
            'sendUpdates' => 'all',
            'conferenceDataVersion' => 1,
        ])->patch($this->calendarUrl($user).'/'.rawurlencode($eventId), $changes);

        $this->throwIfFailed($response->status(), $response->successful());

        return (array) $response->json();
    }

    public function getEvent(User $user, string $eventId): array
    {
        $response = $this->request($user)->get($this->calendarUrl($user).'/'.rawurlencode($eventId));
        if ($response->status() === 404) {
            return [];
        }
        $this->throwIfFailed($response->status(), $response->successful());

        return (array) $response->json();
    }

    public function deleteEvent(User $user, string $eventId): void
    {
        $response = $this->request($user)->withQueryParameters([
            'sendUpdates' => 'all',
        ])->delete($this->calendarUrl($user).'/'.rawurlencode($eventId));

        if ($response->status() !== 404) {
            $this->throwIfFailed($response->status(), $response->successful());
        }
    }

    private function request(User $user): PendingRequest
    {
        return Http::acceptJson()
            ->withToken($this->oauth->getAccessToken($user))
            ->timeout(20);
    }

    private function calendarUrl(User $user): string
    {
        $calendarId = (string) ($user->googleCalendarConnection?->calendar_id ?: 'primary');

        return 'https://www.googleapis.com/calendar/v3/calendars/'.rawurlencode($calendarId).'/events';
    }

    private function validatedEvent(array $event, bool $creating): array
    {
        $allowed = ['summary', 'description', 'location', 'start', 'end', 'attendees', 'recurrence', 'reminders'];
        $event = array_intersect_key($event, array_flip($allowed));
        $rules = [
            'summary' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:8000'],
            'location' => ['sometimes', 'nullable', 'string', 'max:500'],
            'start' => [$creating ? 'required' : 'sometimes', 'array'],
            'start.dateTime' => ['sometimes', 'date'],
            'start.date' => ['sometimes', 'date_format:Y-m-d'],
            'start.timeZone' => ['sometimes', 'string', 'max:100'],
            'end' => [$creating ? 'required' : 'sometimes', 'array'],
            'end.dateTime' => ['sometimes', 'date'],
            'end.date' => ['sometimes', 'date_format:Y-m-d'],
            'end.timeZone' => ['sometimes', 'string', 'max:100'],
            'attendees' => ['sometimes', 'array', 'max:50'],
            'attendees.*.email' => ['required', 'email', 'max:254'],
            'recurrence' => ['sometimes', 'array', 'max:10'],
            'recurrence.*' => ['string', 'max:255'],
            'reminders' => ['sometimes', 'array'],
        ];

        $validated = Validator::make($event, $rules)->validate();
        if ($creating && (empty($validated['start']['dateTime']) && empty($validated['start']['date']))) {
            throw new RuntimeException('O inicio do evento e obrigatorio.');
        }
        if ($creating && (empty($validated['end']['dateTime']) && empty($validated['end']['date']))) {
            throw new RuntimeException('O fim do evento e obrigatorio.');
        }
        if ($creating) {
            $start = new \DateTimeImmutable($validated['start']['dateTime'] ?? $validated['start']['date']);
            $end = new \DateTimeImmutable($validated['end']['dateTime'] ?? $validated['end']['date']);
            if ($end <= $start) {
                throw new RuntimeException('O fim do evento deve ser posterior ao inicio.');
            }
        }

        return $validated;
    }

    private function throwIfFailed(int $status, bool $successful): void
    {
        if (! $successful) {
            throw new RuntimeException('A solicitacao ao Google Calendar falhou (HTTP '.$status.').');
        }
    }
}
