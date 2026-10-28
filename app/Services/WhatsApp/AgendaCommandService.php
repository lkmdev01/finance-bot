<?php

namespace App\Services\WhatsApp;

use App\Exceptions\AiUserTokenLimitExceeded;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\AgendaIntentParser;
use App\Services\AgendaDailySummaryService;
use App\Services\AgendaDigestPreferenceService;
use App\Services\GoogleCalendarService;
use App\Services\GoogleTasksService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AgendaCommandService
{
    private const PENDING_CREATE = 'agenda_create_event';

    private const PENDING_UPDATE = 'agenda_update_event';

    private const PENDING_DELETE = 'agenda_delete_event';

    private const PENDING_SELECTION = 'agenda_event_selection';

    private const PENDING_CLARIFICATION = 'agenda_request_clarification';

    public function __construct(
        private readonly ConversationStateService $stateService,
        private readonly AgendaIntentParser $parser,
        private readonly GoogleCalendarService $calendar,
        private readonly ?GoogleTasksService $tasks = null,
        private readonly ?AgendaDigestPreferenceService $digestPreferences = null,
        private readonly ?AgendaDailySummaryService $dailySummaries = null,
    ) {}

    public function handle(string $message, User $user, WhatsAppContact $contact): ?string
    {
        $state = $this->stateService->getState($contact);
        $pendingIntent = (string) ($state['pending_intent'] ?? '');
        $pending = str_starts_with($pendingIntent, 'agenda_');
        $normalized = Str::of($message)->ascii()->lower()->squish()->toString();
        if ($this->digestPreferences?->handles($normalized)) {
            return $this->digestPreferences->handle($normalized, $user);
        }
        if (! $pending && ! $this->looksLikeAgendaRequest($normalized)) {
            return null;
        }

        if (! $pending && ! empty($state['pending_intent'])) {
            $this->stateService->clearPending($contact);
        }

        if (! $user->googleCalendarConnection || $user->googleCalendarConnection->revoked_at) {
            if ($pending) {
                $this->stateService->clearPending($contact);
            }

            return 'Para cuidar da sua agenda, primeiro conecte o Google Calendar em: '.route('integrations.google-calendar');
        }

        $requiredScopes = (array) config('services.google_calendar.scopes', []);
        if (array_diff($requiredScopes, (array) $user->googleCalendarConnection->scopes) !== []) {
            return 'A conexão do Google Calendar precisa de uma permissão adicional para verificar horários. Atualize as permissões em: '.route('integrations.google-calendar');
        }

        if ($pending && ($state['mode'] ?? '') === 'awaiting_confirmation') {
            return match ($pendingIntent) {
                self::PENDING_CREATE => $this->handleConfirmation($normalized, $user, $contact, $state['pending_payload'] ?? []),
                self::PENDING_UPDATE, self::PENDING_DELETE => $this->handleMutationConfirmation($normalized, $pendingIntent, $user, $contact, $state['pending_payload'] ?? []),
                default => 'Responda *sim* para confirmar ou *não* para cancelar.',
            };
        }

        if ($pending && in_array($normalized, ['nao', 'nao quero', 'cancela', 'cancelar'], true)) {
            $this->stateService->clearPending($contact);

            return 'Tudo bem, cancelei o pedido de agenda.';
        }

        if ($pendingIntent === self::PENDING_SELECTION && ($state['mode'] ?? '') === 'awaiting_clarification') {
            return $this->selectEventCandidate($normalized, $user, $contact, $state['pending_payload'] ?? []);
        }

        $original = $pending
            ? trim((string) data_get($state, 'pending_payload.original_message').' '.$message)
            : $message;

        try {
            $intent = $this->isDailySummaryRequest($normalized)
                ? ['intent' => 'daily_summary']
                : $this->parser->parse($original, CarbonImmutable::now(), (string) config('app.timezone', 'UTC'), $user);
        } catch (Throwable $exception) {
            if ($exception instanceof AiUserTokenLimitExceeded) {
                return 'Você atingiu o limite mensal de '.number_format($exception->limit, 0, ',', '.').' tokens de IA. Ele será renovado no próximo mês.';
            }

            Log::warning('Falha ao interpretar pedido de agenda', [
                'user_id' => $user->id,
                'exception' => $exception::class,
            ]);

            return 'Não consegui entender esse pedido de agenda agora. Tente novamente com o compromisso, a data e o horário.';
        }

        return match ($intent['intent'] ?? 'other') {
            'create_event' => $this->prepareEvent($intent, $original, $user, $contact),
            'list_events' => $this->listEvents($intent, $user),
            'daily_summary' => $this->dailySummary($user),
            'update_event' => $this->findMutationTarget('update', $intent, $original, $user, $contact),
            'delete_event' => $this->findMutationTarget('delete', $intent, $original, $user, $contact),
            default => 'Posso consultar sua agenda ou preparar um compromisso. Para criar, diga o título, a data e os horários de início e fim.',
        };
    }

    private function looksLikeAgendaRequest(string $message): bool
    {
        if ($this->isDailySummaryRequest($message)) {
            return true;
        }
        if (preg_match('/\b(agenda|calendario|compromissos|horario livre)\b/u', $message) === 1) {
            return true;
        }

        $eventType = '(reuniao|reunioes|consulta|consultas|compromisso|evento|dentista|medico|medica|terapia|aula|almoco|cafe|entrevista|visita)';

        return preg_match('/\b(agendar|marcar|marque|marca|criar|crie|adicionar|remarcar|desmarcar|cancelar|mudar|alterar)\b.{0,60}\b'.$eventType.'\b/u', $message) === 1
            || preg_match('/\b'.$eventType.'\b.{0,60}\b(amanha|hoje|segunda|terca|quarta|quinta|sexta|sabado|domingo|as [0-2]?\d[:h])\b/u', $message) === 1;
    }

    private function isDailySummaryRequest(string $message): bool
    {
        return preg_match('/\b(resumo (da )?agenda|agenda (de )?hoje|resumo do meu dia)\b/u', $message) === 1;
    }

    private function prepareEvent(array $intent, string $original, User $user, WhatsAppContact $contact): string
    {
        $event = [
            'summary' => $intent['title'] ?? null,
            'start_at' => $intent['start_at'] ?? null,
            'end_at' => $intent['end_at'] ?? null,
            'time_zone' => $intent['time_zone'] ?: config('app.timezone', 'UTC'),
            'attendees' => array_values(array_filter($intent['attendees'] ?? [], 'is_string')),
        ];

        if ($event['attendees'] !== [] && collect($event['attendees'])->contains(fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            $this->stateService->markAwaitingClarification($contact, self::PENDING_CREATE, [
                'original_message' => $original,
            ]);

            return 'Para convidar participantes, preciso do endereço de e-mail de cada pessoa. Pode informar os e-mails?';
        }

        if (! $event['summary'] || ! $event['start_at'] || ! $event['end_at']) {
            $this->stateService->markAwaitingClarification($contact, self::PENDING_CREATE, [
                'original_message' => $original,
            ]);

            return 'Para preparar o compromisso, preciso do título, da data e dos horários de início e fim. Qual informação falta?';
        }

        try {
            $start = CarbonImmutable::parse($event['start_at']);
            $end = CarbonImmutable::parse($event['end_at']);
            if ($end->lessThanOrEqualTo($start) || ! in_array($event['time_zone'], \DateTimeZone::listIdentifiers(), true)) {
                throw new \RuntimeException('Invalid interval');
            }

            $validated = $this->calendar->validateEventForConfirmation($event);
        } catch (Throwable) {
            $this->stateService->markAwaitingClarification($contact, self::PENDING_CREATE, [
                'original_message' => $original,
            ]);

            return 'Não consegui validar a data e os horários. Pode informar novamente o dia, o horário de início e o de término?';
        }

        try {
            if ($this->calendar->hasConflict($user, $start, $end, $event['time_zone'])) {
                $this->stateService->markAwaitingClarification($contact, self::PENDING_CREATE, [
                    'original_message' => $original,
                ]);

                return 'Esse horário parece ocupado na sua agenda. Escolha outro horário para eu verificar novamente.';
            }
        } catch (Throwable $exception) {
            Log::warning('Não foi possível verificar disponibilidade no Google Calendar', [
                'user_id' => $user->id,
                'exception' => $exception::class,
            ]);

            return 'Não consegui confirmar se esse horário está livre. Não preparei o evento; tente novamente em alguns instantes.';
        }

        $this->stateService->markAwaitingConfirmation($contact, self::PENDING_CREATE, [
            'event' => $validated,
            'requested_at' => now()->toIso8601String(),
        ]);

        $startLabel = $start->setTimezone($event['time_zone'])->format('d/m/Y H:i');
        $endLabel = $end->setTimezone($event['time_zone'])->format('H:i');
        $people = $event['attendees'] === [] ? '' : "\nParticipantes: ".implode(', ', $event['attendees']);

        return "Confirma criar este evento no Google Calendar?\n{$event['summary']}\n{$startLabel}–{$endLabel} ({$event['time_zone']}){$people}\nResponda *sim* para confirmar ou *não* para cancelar.";
    }

    private function handleConfirmation(string $normalized, User $user, WhatsAppContact $contact, array $pending): string
    {
        if (in_array($normalized, ['nao', 'nao quero', 'cancela', 'cancelar'], true)) {
            $this->stateService->clearPending($contact);

            return 'Tudo bem, não criei o evento.';
        }

        if (! in_array($normalized, ['sim', 'confirmo', 'confirmar', 'pode criar'], true)) {
            return 'O evento ainda não foi criado. Responda *sim* para confirmar ou *não* para cancelar.';
        }

        try {
            $event = $pending['event'] ?? [];
            $start = CarbonImmutable::parse($event['start']['dateTime'] ?? '');
            $end = CarbonImmutable::parse($event['end']['dateTime'] ?? '');
            $timezone = (string) ($event['start']['timeZone'] ?? config('app.timezone', 'UTC'));
            if ($this->calendar->hasConflict($user, $start, $end, $timezone)) {
                $this->stateService->clearPending($contact);

                return 'Esse horário ficou ocupado antes da confirmação. Não criei o evento; envie outro horário para eu verificar.';
            }

            $event = $this->calendar->createEvent(
                $user,
                $event,
                'agenda-contact-'.$contact->id.'-'.(string) ($pending['requested_at'] ?? '')
                    .'-'.hash('sha256', json_encode($pending['event'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            );
        } catch (Throwable $exception) {
            Log::warning('Falha ao criar evento do Google Calendar', [
                'user_id' => $user->id,
                'contact_id' => $contact->id,
                'exception' => $exception::class,
            ]);

            return 'Não consegui criar o evento agora. Seu pedido continua pendente; tente responder *sim* novamente daqui a pouco.';
        }

        $this->stateService->clearPending($contact);

        return 'Compromisso criado no Google Calendar: '.($event['htmlLink'] ?? ($pending['event']['summary'] ?? 'evento'));
    }

    private function findMutationTarget(string $operation, array $intent, string $original, User $user, WhatsAppContact $contact): string
    {
        $targetStart = $intent['target_start_at'] ?? null;
        $targetEnd = $intent['target_end_at'] ?? null;
        $targetTitle = trim((string) ($intent['target_title'] ?? ''));
        if (! $targetStart || ! $targetEnd) {
            $this->stateService->markAwaitingClarification($contact, self::PENDING_CLARIFICATION, [
                'original_message' => $original,
            ]);

            return 'Para localizar o compromisso com segurança, informe o dia ou período em que ele está marcado.';
        }

        try {
            $from = CarbonImmutable::parse($targetStart);
            $to = CarbonImmutable::parse($targetEnd);
            if ($to->lessThanOrEqualTo($from) || $to->diffInDays($from) > 32) {
                throw new \RuntimeException('Invalid target period');
            }
            $events = $this->calendar->listEvents($user, $from, $to);
        } catch (Throwable $exception) {
            Log::warning('Falha ao localizar compromisso no Google Calendar', [
                'user_id' => $user->id,
                'exception' => $exception::class,
            ]);

            return 'Não consegui localizar esse compromisso agora. Confirme a data e tente novamente.';
        }

        $normalizedTitle = Str::of($targetTitle)->ascii()->lower()->squish()->toString();
        $candidates = collect($events)->filter(function (array $event) use ($normalizedTitle): bool {
            if (! is_string($event['id'] ?? null) || ! is_array($event['start'] ?? null)) {
                return false;
            }
            if ($normalizedTitle === '') {
                return true;
            }

            $summary = Str::of((string) ($event['summary'] ?? ''))->ascii()->lower()->squish()->toString();
            if ($summary === '') {
                return false;
            }

            return str_contains($summary, $normalizedTitle) || str_contains($normalizedTitle, $summary);
        })->values()->take(10)->all();

        if ($candidates === []) {
            return 'Não encontrei esse compromisso no período informado. Confira o título e a data e tente novamente.';
        }

        $payload = [
            'operation' => $operation,
            'original_message' => $original,
            'changes' => [
                'new_title' => $intent['new_title'] ?? null,
                'new_start_at' => $intent['new_start_at'] ?? null,
                'new_end_at' => $intent['new_end_at'] ?? null,
                'time_zone' => ($intent['time_zone'] ?? null) ?: config('app.timezone', 'UTC'),
            ],
        ];

        if (count($candidates) > 1) {
            $payload['candidates'] = $candidates;
            $this->stateService->markAwaitingClarification($contact, self::PENDING_SELECTION, $payload);

            $verb = $operation === 'delete' ? 'excluir' : 'alterar';

            return "Encontrei mais de um compromisso. Qual você quer {$verb}?\n".$this->formatCandidates($candidates)."\nResponda com o número da opção.";
        }

        return $this->prepareMutation($operation, $candidates[0], $payload['changes'], $user, $contact);
    }

    private function selectEventCandidate(string $normalized, User $user, WhatsAppContact $contact, array $pending): string
    {
        if (! preg_match('/^[1-9]\d?$/D', $normalized)) {
            return "Responda com o número do compromisso que deseja alterar ou excluir, ou *não* para cancelar.";
        }

        $candidate = $pending['candidates'][(int) $normalized - 1] ?? null;
        if (! is_array($candidate)) {
            return 'Esse número não corresponde a uma opção. Escolha um dos números mostrados ou responda *não* para cancelar.';
        }

        return $this->prepareMutation(
            (string) ($pending['operation'] ?? 'delete'),
            $candidate,
            (array) ($pending['changes'] ?? []),
            $user,
            $contact,
        );
    }

    private function prepareMutation(string $operation, array $event, array $requestedChanges, User $user, WhatsAppContact $contact): string
    {
        $eventId = (string) ($event['id'] ?? '');
        if ($eventId === '') {
            return 'Não consegui identificar o compromisso com segurança. Consulte a agenda e tente novamente.';
        }

        $changes = [];
        $newTitle = trim((string) ($requestedChanges['new_title'] ?? ''));
        if ($operation === 'update' && $newTitle !== '') {
            $changes['summary'] = mb_substr($newTitle, 0, 255);
        }

        $newStart = $requestedChanges['new_start_at'] ?? null;
        $newEnd = $requestedChanges['new_end_at'] ?? null;
        $eventStart = $event['start']['dateTime'] ?? null;
        $eventEnd = $event['end']['dateTime'] ?? null;
        if ($operation === 'update' && $newStart) {
            try {
                if (! $eventStart || ! $eventEnd) {
                    throw new \RuntimeException('All-day event');
                }
                $start = CarbonImmutable::parse($newStart);
                $oldStart = CarbonImmutable::parse($eventStart);
                $oldEnd = CarbonImmutable::parse($eventEnd);
                $end = $newEnd ? CarbonImmutable::parse($newEnd) : $start->addSeconds($oldStart->diffInSeconds($oldEnd));
                if ($end->lessThanOrEqualTo($start)) {
                    throw new \RuntimeException('Invalid interval');
                }
                $timezone = (string) ($requestedChanges['time_zone'] ?? config('app.timezone', 'UTC'));
                if (! in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
                    throw new \RuntimeException('Invalid timezone');
                }
                if ($this->calendar->hasConflict($user, $start, $end, $timezone, $eventId)) {
                    return 'O novo horário conflita com outro compromisso. Não alterei nada; envie novamente o pedido completo com outro horário.';
                }
                $changes['start'] = ['dateTime' => $start->toIso8601String(), 'timeZone' => $timezone];
                $changes['end'] = ['dateTime' => $end->toIso8601String(), 'timeZone' => $timezone];
            } catch (Throwable $exception) {
                Log::warning('Não foi possível validar a alteração de horário do Google Calendar', [
                    'user_id' => $user->id,
                    'exception' => $exception::class,
                ]);

                return 'Não consegui validar o novo horário. Informe novamente o dia e os horários de início e fim.';
            }
        } elseif ($operation === 'update' && $newEnd) {
            return 'Para mudar o horário, também preciso do novo horário de início.';
        }

        if ($operation === 'update' && $changes === []) {
            return 'Diga o que deseja mudar no compromisso, como o título ou o horário.';
        }

        $pendingIntent = $operation === 'delete' ? self::PENDING_DELETE : self::PENDING_UPDATE;
        $payload = [
            'event_id' => $eventId,
            'summary' => (string) ($event['summary'] ?? 'Sem título'),
            'event_start' => $eventStart ?? ($event['start']['date'] ?? null),
            'event_end' => $event['end']['dateTime'] ?? ($event['end']['date'] ?? null),
            'changes' => $changes,
            'requested_at' => now()->toIso8601String(),
        ];
        $this->stateService->markAwaitingConfirmation($contact, $pendingIntent, $payload);

        if ($operation === 'delete') {
            $when = $payload['event_start'] ? CarbonImmutable::parse($payload['event_start'])->setTimezone((string) config('app.timezone', 'UTC'))->format('d/m/Y H:i') : 'data não informada';

            return "Confirma excluir *{$payload['summary']}* ({$when}) do Google Calendar? Responda *sim* para excluir ou *não* para cancelar.";
        }

        $description = $payload['summary'];
        if (isset($changes['summary'])) {
            $description .= ' (novo título: '.$changes['summary'].')';
        }
        if (isset($changes['start']['dateTime'], $changes['end']['dateTime'])) {
            $timezone = (string) ($changes['start']['timeZone'] ?? config('app.timezone', 'UTC'));
            $newStart = CarbonImmutable::parse($changes['start']['dateTime'])->setTimezone($timezone)->format('d/m/Y H:i');
            $newEnd = CarbonImmutable::parse($changes['end']['dateTime'])->setTimezone($timezone)->format('H:i');
            $description .= " para {$newStart}–{$newEnd}";
        }

        return "Confirma alterar o compromisso para *{$description}*? Responda *sim* para confirmar ou *não* para cancelar.";
    }

    private function handleMutationConfirmation(string $normalized, string $pendingIntent, User $user, WhatsAppContact $contact, array $pending): string
    {
        if (in_array($normalized, ['nao', 'nao quero', 'cancela', 'cancelar'], true)) {
            $this->stateService->clearPending($contact);

            return 'Tudo bem, não alterei sua agenda.';
        }
        if (! in_array($normalized, ['sim', 'confirmo', 'confirmar', 'pode criar', 'pode alterar', 'pode excluir'], true)) {
            return 'A alteração ainda não foi feita. Responda *sim* para confirmar ou *não* para cancelar.';
        }

        try {
            $currentEvent = $this->calendar->getEvent($user, (string) $pending['event_id']);
            if (($currentEvent['summary'] ?? 'Sem título') !== ($pending['summary'] ?? 'Sem título')
                || ($currentEvent['start']['dateTime'] ?? ($currentEvent['start']['date'] ?? null)) !== ($pending['event_start'] ?? null)
                || ($currentEvent['end']['dateTime'] ?? ($currentEvent['end']['date'] ?? null)) !== ($pending['event_end'] ?? null)) {
                $this->stateService->clearPending($contact);

                return 'Esse compromisso mudou desde a consulta. Não fiz a alteração; consulte a agenda novamente para confirmar os dados atuais.';
            }

            if ($pendingIntent === self::PENDING_DELETE) {
                $this->calendar->deleteEvent($user, (string) $pending['event_id']);
                $reply = 'Compromisso excluído do Google Calendar: '.($pending['summary'] ?? 'evento');
            } else {
                if (isset($pending['changes']['start']['dateTime'], $pending['changes']['end']['dateTime'])
                    && $this->calendar->hasConflict(
                        $user,
                        CarbonImmutable::parse($pending['changes']['start']['dateTime']),
                        CarbonImmutable::parse($pending['changes']['end']['dateTime']),
                        (string) ($pending['changes']['start']['timeZone'] ?? config('app.timezone', 'UTC')),
                        (string) $pending['event_id'],
                    )) {
                    $this->stateService->clearPending($contact);

                    return 'O novo horário ficou ocupado antes da confirmação. Não alterei o compromisso; envie novamente o pedido completo com outro horário.';
                }

                $this->calendar->updateEvent($user, (string) $pending['event_id'], (array) ($pending['changes'] ?? []));
                $reply = 'Compromisso atualizado no Google Calendar: '.($pending['changes']['summary'] ?? $pending['summary'] ?? 'evento');
            }
        } catch (Throwable $exception) {
            Log::warning('Falha ao alterar compromisso do Google Calendar', [
                'user_id' => $user->id,
                'contact_id' => $contact->id,
                'exception' => $exception::class,
            ]);

            return 'Não consegui concluir a alteração agora. Seu pedido continua pendente; tente confirmar novamente em alguns instantes.';
        }

        $this->stateService->clearPending($contact);

        return $reply;
    }

    private function formatCandidates(array $candidates): string
    {
        return collect($candidates)->values()->map(function (array $event, int $index): string {
            $when = $event['start']['dateTime'] ?? $event['start']['date'] ?? null;
            $label = $when ? CarbonImmutable::parse($when)->setTimezone((string) config('app.timezone', 'UTC'))->format('d/m/Y H:i') : 'horário não informado';

            return ($index + 1).'. '.($event['summary'] ?? 'Sem título').' — '.$label;
        })->implode("\n");
    }

    private function listEvents(array $intent, User $user): string
    {
        try {
            $timezone = (string) config('app.timezone', 'UTC');
            $from = isset($intent['period_start']) ? CarbonImmutable::parse($intent['period_start']) : CarbonImmutable::now($timezone)->startOfDay();
            $to = isset($intent['period_end']) ? CarbonImmutable::parse($intent['period_end']) : $from->addDays(7)->endOfDay();
            $events = $this->calendar->listEvents($user, $from, $to);
        } catch (Throwable $exception) {
            Log::warning('Falha ao consultar Google Calendar', [
                'user_id' => $user->id,
                'exception' => $exception::class,
            ]);

            return 'Não consegui consultar sua agenda agora. Tente novamente em alguns instantes.';
        }

        if ($events === []) {
            return 'Não encontrei compromissos nesse período.';
        }

        $lines = collect($events)->take(12)->map(function (array $event) use ($timezone): string {
            $when = data_get($event, 'start.dateTime') ?? data_get($event, 'start.date');
            $label = $when ? CarbonImmutable::parse($when)->setTimezone($timezone)->format('d/m H:i') : 'horário não informado';

            return $label.' — '.($event['summary'] ?? 'Sem título');
        });

        return "Sua agenda nesse período:\n".$lines->implode("\n");
    }

    private function dailySummary(User $user): string
    {
        $timezone = (string) config('app.timezone', 'America/Sao_Paulo');
        try {
            $service = $this->dailySummaries ?? new AgendaDailySummaryService($this->calendar, $this->tasks);

            return $service->summarize($user, CarbonImmutable::now($timezone));
        } catch (Throwable $exception) {
            Log::warning('Falha ao gerar resumo diário do Google Calendar', [
                'user_id' => $user->id,
                'exception' => $exception::class,
            ]);

            return 'Não consegui consultar sua agenda para montar o resumo de hoje. Tente novamente em alguns instantes.';
        }
    }
}
