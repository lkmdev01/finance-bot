<?php

namespace App\Services;

use App\Exceptions\GoogleCalendarApiException;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
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

        $this->throwIfFailed($response->status(), $response->successful(), $response->json('error.errors.0.reason'));

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

        return $this->freeBusyIntervals($user, $start, $end, $timezone) !== [];
    }

    /**
     * Find up to three nearby alternatives on the same local day with one FreeBusy request.
     * Candidate starts are spaced 30 minutes apart and limited to four hours after the request.
     *
     * @return array<int, array{start: CarbonImmutable, end: CarbonImmutable}>
     */
    public function suggestAvailableSlots(
        User $user,
        CarbonImmutable $requestedStart,
        CarbonImmutable $requestedEnd,
        string $timezone,
        int $limit = 3,
        ?string $workdayStart = null,
        ?string $workdayEnd = null,
    ): array {
        $duration = $requestedStart->diffInSeconds($requestedEnd);
        if ($duration <= 0 || ! in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            throw new RuntimeException('Intervalo inválido para sugerir horários.');
        }

        $requestedStart = $requestedStart->setTimezone($timezone);
        $requestedEnd = $requestedEnd->setTimezone($timezone);
        $dayEnd = $requestedStart->endOfDay();
        $searchLimit = $requestedStart->addHours(4);
        $firstStart = $requestedStart->addMinutes(30);
        if ($workdayStart !== null && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $workdayStart) === 1) {
            [$hour, $minute] = array_map('intval', explode(':', $workdayStart));
            $workStart = $requestedStart->startOfDay()->setTime($hour, $minute);
            if ($firstStart->lessThan($workStart)) {
                $firstStart = $workStart;
            }
        }
        $workEnd = null;
        if ($workdayEnd !== null && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $workdayEnd) === 1) {
            [$hour, $minute] = array_map('intval', explode(':', $workdayEnd));
            $workEnd = $requestedStart->startOfDay()->setTime($hour, $minute);
        }
        $candidates = [];
        for ($start = $firstStart; $start->lessThanOrEqualTo($searchLimit); $start = $start->addMinutes(30)) {
            $end = $start->addSeconds($duration);
            if ($end->greaterThan($dayEnd) || ($workEnd !== null && $end->greaterThan($workEnd))) {
                break;
            }
            $candidates[] = ['start' => $start, 'end' => $end];
        }

        if ($candidates === []) {
            return [];
        }

        $busy = $this->freeBusyIntervals(
            $user,
            $candidates[0]['start'],
            $candidates[array_key_last($candidates)]['end'],
            $timezone,
        );

        return collect($candidates)
            ->reject(fn (array $candidate): bool => collect($busy)->contains(fn (array $interval): bool => CarbonImmutable::parse($interval['start'])->lessThan($candidate['end'])
                && CarbonImmutable::parse($interval['end'])->greaterThan($candidate['start'])))
            ->take(max(1, min($limit, 3)))
            ->values()
            ->all();
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

        $this->throwIfFailed($response->status(), $response->successful(), $response->json('error.errors.0.reason'));

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
        if (($event['recurrence'] ?? []) !== []) {
            $payload['recurrence'] = $event['recurrence'];
        }
        if (isset($event['reminder_minutes'])) {
            $payload['reminders'] = [
                'useDefault' => false,
                'overrides' => [['method' => 'popup', 'minutes' => $event['reminder_minutes']]],
            ];
        }

        return $this->validatedEvent($payload, true);
    }

    public function updateEvent(User $user, string $eventId, array $changes): array
    {
        $changes = $this->validatedEvent($changes, false);
        $response = $this->request($user)->withQueryParameters([
            'sendUpdates' => 'all',
            'conferenceDataVersion' => 1,
        ])->patch($this->calendarUrl($user).'/'.rawurlencode($eventId), $changes);

        $this->throwIfFailed($response->status(), $response->successful(), $response->json('error.errors.0.reason'));

        return (array) $response->json();
    }

    public function getEvent(User $user, string $eventId): array
    {
        $response = $this->request($user)->get($this->calendarUrl($user).'/'.rawurlencode($eventId));
        if ($response->status() === 404) {
            return [];
        }
        $this->throwIfFailed($response->status(), $response->successful(), $response->json('error.errors.0.reason'));

        return (array) $response->json();
    }

    public function deleteEvent(User $user, string $eventId): void
    {
        $response = $this->request($user)->withQueryParameters([
            'sendUpdates' => 'all',
        ])->delete($this->calendarUrl($user).'/'.rawurlencode($eventId));

        if ($response->status() !== 404) {
            $this->throwIfFailed($response->status(), $response->successful(), $response->json('error.errors.0.reason'));
        }
    }

    private function request(User $user): PendingRequest
    {
        return Http::acceptJson()
            ->withToken($this->oauth->getAccessToken($user))
            ->timeout(20);
    }

    /** @return array<int, array{start: string, end: string}> */
    private function freeBusyIntervals(User $user, CarbonInterface $from, CarbonInterface $to, string $timezone): array
    {
        $response = $this->request($user)->post('https://www.googleapis.com/calendar/v3/freeBusy', [
            'timeMin' => $from->toIso8601String(),
            'timeMax' => $to->toIso8601String(),
            'timeZone' => $timezone,
            'items' => [['id' => (string) ($user->googleCalendarConnection?->calendar_id ?: 'primary')]],
        ]);

        $this->throwIfFailed($response->status(), $response->successful(), $response->json('error.errors.0.reason'));
        $calendarId = (string) ($user->googleCalendarConnection?->calendar_id ?: 'primary');
        $calendar = (array) $response->json('calendars', []);
        $calendar = $calendar[$calendarId] ?? null;
        if (! is_array($calendar) || ! empty($calendar['errors'])) {
            throw new RuntimeException('O Google Calendar não confirmou a disponibilidade do horário.');
        }

        return array_values(array_filter((array) ($calendar['busy'] ?? []), static fn (mixed $interval): bool => is_array($interval) && is_string($interval['start'] ?? null) && is_string($interval['end'] ?? null)));
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
            'recurrence.*' => ['string', 'max:255', 'regex:/^RRULE:FREQ=(DAILY|WEEKLY|MONTHLY|YEARLY)(;INTERVAL=[1-9][0-9]?)?(;BYDAY=(MO|TU|WE|TH|FR|SA|SU)(,(MO|TU|WE|TH|FR|SA|SU))*)?(;(COUNT=[1-9][0-9]{0,3}|UNTIL=\d{8}T\d{6}Z))?$/D'],
            'reminders' => ['sometimes', 'array'],
            'reminders.useDefault' => ['sometimes', 'boolean'],
            'reminders.overrides' => ['sometimes', 'array', 'max:5'],
            'reminders.overrides.*.method' => ['required', 'in:email,popup'],
            'reminders.overrides.*.minutes' => ['required', 'integer', 'between:0,40320'],
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

    private function throwIfFailed(int $status, bool $successful, mixed $reason = null): void
    {
        if (! $successful) {
            throw new GoogleCalendarApiException($status, is_string($reason) ? $reason : null);
        }
    }
}
