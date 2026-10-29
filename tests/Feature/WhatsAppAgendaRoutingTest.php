<?php

use App\Models\GoogleCalendarConnection;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\AgendaIntentParser;
use App\Services\GoogleCalendarService;
use App\Services\GoogleTasksService;
use App\Services\Meetings\MeetingCommandService;
use App\Services\WhatsApp\AgendaCommandService;
use App\Services\WhatsApp\ConversationOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;

uses(RefreshDatabase::class);

function agendaUserWithCalendar(): User
{
    $user = User::factory()->create();
    GoogleCalendarConnection::create([
        'user_id' => $user->id,
        'google_email' => 'agenda@example.test',
        'refresh_token' => Crypt::encryptString('test-refresh-token'),
        'scopes' => [
            'https://www.googleapis.com/auth/calendar.events.owned',
            'https://www.googleapis.com/auth/calendar.events.freebusy',
        ],
        'calendar_id' => 'primary',
        'connected_at' => now(),
    ]);

    return $user;
}

function agendaContact(User $user, array $state = []): WhatsAppContact
{
    return WhatsAppContact::factory()->create([
        'user_id' => $user->id,
        'phone_number' => '551100001'.str_pad((string) (WhatsAppContact::query()->count() + 1), 4, '0', STR_PAD_LEFT),
        'conversation_state' => $state,
    ]);
}

it('routes calendar requests before the financial agent and leaves financial messages to the existing path', function (): void {
    $user = User::factory()->create();
    $contact = agendaContact($user);

    $agenda = Mockery::mock(AgendaCommandService::class);
    $agenda->shouldReceive('handle')->once()->with('marca uma consulta amanhã', $user, $contact)->andReturn('Confirma criar?');
    $agenda->shouldReceive('handle')->once()->with('gastei 80 no mercado', $user, Mockery::type(WhatsAppContact::class))->andReturnNull();
    app()->instance(AgendaCommandService::class, $agenda);

    $meetings = Mockery::mock(MeetingCommandService::class);
    $meetings->shouldReceive('handle')->twice()->andReturnNull();
    app()->instance(MeetingCommandService::class, $meetings);

    $handled = app(ConversationOrchestrator::class)->beforeAI('marca uma consulta amanhã', $user, $contact);
    expect($handled)->toMatchArray([
        'handled' => true,
        'classification' => 'agenda_command',
        'domain' => 'agenda',
        'reply' => 'Confirma criar?',
    ]);

    $contact2 = agendaContact($user);
    expect(app(ConversationOrchestrator::class)->beforeAI('gastei 80 no mercado', $user, $contact2))->toBeNull();
});

it('asks for calendar connection rather than passing agenda intent to the financial agent', function (): void {
    $user = User::factory()->create();
    $contact = agendaContact($user);

    $reply = app(AgendaCommandService::class)->handle('quero ver minha agenda esta semana', $user, $contact);

    expect($reply)->toContain('conecte o Google Calendar');
});

it('recognizes common Portuguese agenda phrases across lookup, availability, and creation', function (string $message): void {
    $user = agendaUserWithCalendar();
    $contact = agendaContact($user);
    $parser = Mockery::mock(AgendaIntentParser::class);
    $parser->shouldReceive('parse')->once()->andReturn([
        'intent' => 'list_events',
        'period_start' => now('America/Sao_Paulo')->startOfDay()->toIso8601String(),
        'period_end' => now('America/Sao_Paulo')->addWeek()->endOfDay()->toIso8601String(),
    ]);
    app()->instance(AgendaIntentParser::class, $parser);
    $calendar = Mockery::mock(GoogleCalendarService::class);
    $calendar->shouldReceive('listEvents')->once()->andReturn([]);
    app()->instance(GoogleCalendarService::class, $calendar);

    expect(app(AgendaCommandService::class)->handle($message, $user, $contact))->toBe('Não encontrei compromissos nesse período.');
})->with([
    'o que tenho na quarta?',
    'estou livre amanhã das 10 às 11?',
    'quais são meus compromissos esta semana?',
    'marque consulta com Ana amanhã',
]);

it('saves per-user agenda preferences and reports them back', function (): void {
    $user = User::factory()->create();
    $preferences = app(App\Services\AgendaUserPreferenceService::class);

    expect($preferences->handle('duração padrão 30 minutos', $user))->toContain('30 minutos')
        ->and($preferences->handle('lembrete padrão 10 minutos antes', $user))->toContain('10 minutos antes')
        ->and($preferences->handle('horário de trabalho das 9 às 17', $user))->toContain('09:00:00–17:00:00')
        ->and($preferences->handle('preferências da agenda', $user))->toContain('Duração padrão: 30 min')
        ->and($preferences->handle('preferências da agenda', $user))->toContain('Janela preferida: 09:00–17:00')
        ->and($preferences->handle('desative o lembrete padrão', $user))->toContain('Removi essa preferência')
        ->and($preferences->handle('preferências da agenda', $user))->toContain('Lembrete padrão: não definido');
});

it('honors a one-time no-reminder request over the users configured default', function (): void {
    $user = agendaUserWithCalendar();
    App\Models\AgendaUserPreference::query()->create([
        'user_id' => $user->id,
        'default_reminder_minutes' => 15,
    ]);
    $contact = agendaContact($user);
    $parser = Mockery::mock(AgendaIntentParser::class);
    $parser->shouldReceive('parse')->once()->andReturn([
        'intent' => 'create_event',
        'title' => 'Consulta',
        'start_at' => '2026-11-10T10:00:00-03:00',
        'end_at' => '2026-11-10T10:30:00-03:00',
        'time_zone' => 'America/Sao_Paulo',
        'attendees' => [],
        'recurrence' => [],
        'reminder_minutes' => null,
        'disable_reminder' => true,
    ]);
    app()->instance(AgendaIntentParser::class, $parser);
    $calendar = Mockery::mock(GoogleCalendarService::class);
    $calendar->shouldReceive('validateEventForConfirmation')->once()->withArgs(fn (array $event): bool => $event['reminder_minutes'] === null)->andReturn([
        'summary' => 'Consulta',
        'start' => ['dateTime' => '2026-11-10T10:00:00-03:00', 'timeZone' => 'America/Sao_Paulo'],
        'end' => ['dateTime' => '2026-11-10T10:30:00-03:00', 'timeZone' => 'America/Sao_Paulo'],
        'attendees' => [],
    ]);
    $calendar->shouldReceive('hasConflict')->once()->andReturnFalse();
    app()->instance(GoogleCalendarService::class, $calendar);

    $reply = app(AgendaCommandService::class)->handle('marque consulta amanhã às 10, sem lembrete', $user, $contact);

    expect(str_contains($reply, 'Lembrete: nenhum'))->toBeTrue()
        ->and(str_contains($reply, '15 min antes'))->toBeFalse();
});

it('applies a configured default duration and makes recurrence, guests, and reminder explicit in confirmation', function (): void {
    $user = agendaUserWithCalendar();
    App\Models\AgendaUserPreference::query()->create([
        'user_id' => $user->id,
        'default_event_duration_minutes' => 30,
        'default_reminder_minutes' => 10,
    ]);
    $contact = agendaContact($user);
    $parser = Mockery::mock(AgendaIntentParser::class);
    $parser->shouldReceive('parse')->once()->andReturn([
        'intent' => 'create_event',
        'title' => 'Revisão semanal',
        'start_at' => '2026-11-10T10:00:00-03:00',
        'end_at' => null,
        'time_zone' => 'America/Sao_Paulo',
        'attendees' => ['ana@example.test'],
        'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=4'],
        'reminder_minutes' => null,
    ]);
    app()->instance(AgendaIntentParser::class, $parser);

    $calendar = Mockery::mock(GoogleCalendarService::class);
    $calendar->shouldReceive('validateEventForConfirmation')->once()->withArgs(function (array $event): bool {
        return $event['end_at'] === '2026-11-10T10:30:00-03:00'
            && $event['attendees'] === ['ana@example.test']
            && $event['recurrence'] === ['RRULE:FREQ=WEEKLY;COUNT=4']
            && $event['reminder_minutes'] === 10;
    })->andReturn([
        'summary' => 'Revisão semanal',
        'start' => ['dateTime' => '2026-11-10T10:00:00-03:00', 'timeZone' => 'America/Sao_Paulo'],
        'end' => ['dateTime' => '2026-11-10T10:30:00-03:00', 'timeZone' => 'America/Sao_Paulo'],
        'attendees' => [['email' => 'ana@example.test']],
        'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=4'],
        'reminders' => ['useDefault' => false, 'overrides' => [['method' => 'popup', 'minutes' => 10]]],
    ]);
    $calendar->shouldReceive('hasConflict')->once()->andReturnFalse();
    app()->instance(GoogleCalendarService::class, $calendar);

    $reply = app(AgendaCommandService::class)->handle('marque evento revisão semanal terça às 10', $user, $contact);

    expect(str_contains($reply, 'Duração padrão aplicada: 30 min'))->toBeTrue()
        ->and(str_contains($reply, 'ana@example.test'))->toBeTrue()
        ->and(str_contains($reply, 'Repetição: FREQ=WEEKLY;COUNT=4'))->toBeTrue()
        ->and(str_contains($reply, 'Lembrete: 10 min antes'))->toBeTrue();
});

it('builds a daily agenda summary with events and due tasks without including future tasks', function (): void {
    $user = agendaUserWithCalendar();
    $user->googleCalendarConnection->forceFill([
        'scopes' => [
            'https://www.googleapis.com/auth/calendar.events.owned',
            'https://www.googleapis.com/auth/calendar.events.freebusy',
            'https://www.googleapis.com/auth/tasks',
        ],
    ])->save();
    $contact = agendaContact($user);
    $parser = Mockery::mock(AgendaIntentParser::class);
    $parser->shouldNotReceive('parse');
    app()->instance(AgendaIntentParser::class, $parser);

    $calendar = Mockery::mock(GoogleCalendarService::class);
    $calendar->shouldReceive('listEvents')->once()->andReturn([
        ['id' => 'event-1', 'summary' => 'Dentista', 'start' => ['dateTime' => now('America/Sao_Paulo')->setTime(10, 0)->toIso8601String()]],
    ]);
    app()->instance(GoogleCalendarService::class, $calendar);

    $today = now(config('app.timezone', 'America/Sao_Paulo'))->format('Y-m-d');
    $tomorrow = now(config('app.timezone', 'America/Sao_Paulo'))->addDay()->format('Y-m-d');
    $tasks = Mockery::mock(GoogleTasksService::class);
    $tasks->shouldReceive('listTasks')->once()->with($user, 100)->andReturn([
        ['id' => 'today-task', 'title' => 'Enviar proposta', 'due' => $today.'T00:00:00.000Z', 'status' => 'needsAction'],
        ['id' => 'future-task', 'title' => 'Pagar boleto', 'due' => $tomorrow.'T00:00:00.000Z', 'status' => 'needsAction'],
    ]);
    app()->instance(GoogleTasksService::class, $tasks);

    $reply = app(AgendaCommandService::class)->handle('resumo da agenda de hoje', $user, $contact);

    expect($reply)->toContain('Resumo de hoje')
        ->and($reply)->toContain('Dentista')
        ->and($reply)->toContain('Enviar proposta')
        ->and($reply)->not->toContain('Pagar boleto');
});

it('requires explicit confirmation before creating an event and creates it idempotently after confirmation', function (): void {
    $user = agendaUserWithCalendar();
    $contact = agendaContact($user);
    $parser = Mockery::mock(AgendaIntentParser::class);
    $parser->shouldReceive('parse')->once()->andReturn([
        'intent' => 'create_event',
        'title' => 'Dentista',
        'start_at' => '2026-11-10T10:00:00-03:00',
        'end_at' => '2026-11-10T11:00:00-03:00',
        'time_zone' => 'America/Sao_Paulo',
        'attendees' => [],
    ]);
    app()->instance(AgendaIntentParser::class, $parser);

    $calendar = Mockery::mock(GoogleCalendarService::class);
    $calendar->shouldReceive('validateEventForConfirmation')->once()->andReturn([
        'summary' => 'Dentista',
        'start' => ['dateTime' => '2026-11-10T10:00:00-03:00', 'timeZone' => 'America/Sao_Paulo'],
        'end' => ['dateTime' => '2026-11-10T11:00:00-03:00', 'timeZone' => 'America/Sao_Paulo'],
        'attendees' => [],
    ]);
    $calendar->shouldReceive('hasConflict')->twice()->andReturnFalse();
    $calendar->shouldReceive('createEvent')->once()->withArgs(function (User $actual, array $event, string $key) use ($user): bool {
        return $actual->is($user)
            && $event['summary'] === 'Dentista'
            && str_starts_with($key, 'agenda-contact-');
    })->andReturn(['htmlLink' => 'https://calendar.google.test/event']);
    app()->instance(GoogleCalendarService::class, $calendar);

    $service = app(AgendaCommandService::class);
    expect($service->handle('marca dentista dia 10 às 10', $user, $contact))->toContain('Responda *sim*');
    expect(data_get($contact->fresh()->conversation_state, 'pending_intent'))->toBe('agenda_create_event');

    expect($service->handle('sim', $user, $contact))->toContain('Compromisso criado');
    expect(data_get($contact->fresh()->conversation_state, 'pending_intent'))->toBeNull();
});

it('does not create an event when the user cancels the confirmation', function (): void {
    $user = agendaUserWithCalendar();
    $contact = agendaContact($user, [
        'mode' => 'awaiting_confirmation',
        'pending_intent' => 'agenda_create_event',
        'pending_payload' => ['event' => ['summary' => 'Consulta'], 'requested_at' => now()->toIso8601String()],
    ]);
    $calendar = Mockery::mock(GoogleCalendarService::class);
    $calendar->shouldNotReceive('createEvent');
    app()->instance(GoogleCalendarService::class, $calendar);

    expect(app(AgendaCommandService::class)->handle('não', $user, $contact))->toBe('Tudo bem, não criei o evento.');
    expect(data_get($contact->fresh()->conversation_state, 'pending_intent'))->toBeNull();
});

it('reschedules only the selected event and confirms it before updating', function (): void {
    $user = agendaUserWithCalendar();
    $contact = agendaContact($user);
    $event = [
        'id' => 'event-owned-by-this-user',
        'summary' => 'Consulta com Ana',
        'start' => ['dateTime' => '2026-11-10T10:00:00-03:00'],
        'end' => ['dateTime' => '2026-11-10T11:00:00-03:00'],
    ];
    $parser = Mockery::mock(AgendaIntentParser::class);
    $parser->shouldReceive('parse')->once()->andReturn([
        'intent' => 'update_event',
        'target_title' => 'consulta com ana',
        'target_start_at' => '2026-11-10T00:00:00-03:00',
        'target_end_at' => '2026-11-11T00:00:00-03:00',
        'new_title' => null,
        'new_start_at' => '2026-11-10T15:00:00-03:00',
        'new_end_at' => null,
        'time_zone' => 'America/Sao_Paulo',
    ]);
    app()->instance(AgendaIntentParser::class, $parser);

    $calendar = Mockery::mock(GoogleCalendarService::class);
    $calendar->shouldReceive('listEvents')->once()->andReturn([$event]);
    $calendar->shouldReceive('hasConflict')->twice()->andReturnFalse();
    $calendar->shouldReceive('getEvent')->once()->with($user, 'event-owned-by-this-user')->andReturn($event);
    $calendar->shouldReceive('updateEvent')->once()->withArgs(function (User $actual, string $id, array $changes) use ($user): bool {
        return $actual->is($user)
            && $id === 'event-owned-by-this-user'
            && $changes['start']['dateTime'] === '2026-11-10T15:00:00-03:00'
            && $changes['end']['dateTime'] === '2026-11-10T16:00:00-03:00';
    })->andReturn([]);
    app()->instance(GoogleCalendarService::class, $calendar);

    $service = app(AgendaCommandService::class);
    expect($service->handle('muda consulta com Ana de amanhã para 15h', $user, $contact))->toContain('Responda *sim*');
    expect($service->handle('sim', $user, $contact))->toContain('Compromisso atualizado');
    expect(data_get($contact->fresh()->conversation_state, 'pending_intent'))->toBeNull();
});

it('deletes an event only after user confirmation and verifies its identity again', function (): void {
    $user = agendaUserWithCalendar();
    $contact = agendaContact($user);
    $event = [
        'id' => 'event-owned-by-this-user',
        'summary' => 'Dentista',
        'start' => ['dateTime' => '2026-11-10T10:00:00-03:00'],
        'end' => ['dateTime' => '2026-11-10T11:00:00-03:00'],
    ];
    $parser = Mockery::mock(AgendaIntentParser::class);
    $parser->shouldReceive('parse')->once()->andReturn([
        'intent' => 'delete_event',
        'target_title' => 'dentista',
        'target_start_at' => '2026-11-10T00:00:00-03:00',
        'target_end_at' => '2026-11-11T00:00:00-03:00',
    ]);
    app()->instance(AgendaIntentParser::class, $parser);

    $calendar = Mockery::mock(GoogleCalendarService::class);
    $calendar->shouldReceive('listEvents')->once()->andReturn([$event]);
    $calendar->shouldReceive('getEvent')->once()->with($user, 'event-owned-by-this-user')->andReturn($event);
    $calendar->shouldReceive('deleteEvent')->once()->with($user, 'event-owned-by-this-user');
    app()->instance(GoogleCalendarService::class, $calendar);

    $service = app(AgendaCommandService::class);
    expect($service->handle('cancela o dentista de amanhã', $user, $contact))->toContain('Responda *sim*');
    expect($service->handle('sim', $user, $contact))->toContain('Compromisso excluído');
    expect(data_get($contact->fresh()->conversation_state, 'pending_intent'))->toBeNull();
});

it('keeps numbered event selection working and confirms the selected event before deletion', function (): void {
    $user = agendaUserWithCalendar();
    $contact = agendaContact($user);
    $first = [
        'id' => 'dentista-1',
        'summary' => 'Dentista Centro',
        'start' => ['dateTime' => '2026-11-10T10:00:00-03:00'],
        'end' => ['dateTime' => '2026-11-10T11:00:00-03:00'],
    ];
    $second = [
        'id' => 'dentista-2',
        'summary' => 'Dentista Unidade Sul',
        'start' => ['dateTime' => '2026-11-10T14:00:00-03:00'],
        'end' => ['dateTime' => '2026-11-10T15:00:00-03:00'],
    ];
    $parser = Mockery::mock(AgendaIntentParser::class);
    $parser->shouldReceive('parse')->once()->andReturn([
        'intent' => 'delete_event',
        'target_title' => 'dentista',
        'target_start_at' => '2026-11-10T00:00:00-03:00',
        'target_end_at' => '2026-11-11T00:00:00-03:00',
    ]);
    app()->instance(AgendaIntentParser::class, $parser);
    $calendar = Mockery::mock(GoogleCalendarService::class);
    $calendar->shouldReceive('listEvents')->once()->andReturn([$first, $second]);
    $calendar->shouldReceive('getEvent')->once()->with($user, 'dentista-2')->andReturn($second);
    $calendar->shouldReceive('deleteEvent')->once()->with($user, 'dentista-2');
    app()->instance(GoogleCalendarService::class, $calendar);

    $service = app(AgendaCommandService::class);
    expect($service->handle('exclua dentista amanhã', $user, $contact))->toContain('1. Dentista Centro')
        ->and(data_get($contact->fresh()->conversation_state, 'pending_intent'))->toBe('agenda_event_selection');
    expect($service->handle('2', $user, $contact))->toContain('Confirma excluir *Dentista Unidade Sul*')
        ->and(data_get($contact->fresh()->conversation_state, 'mode'))->toBe('awaiting_confirmation');
    expect($service->handle('sim', $user, $contact))->toContain('Compromisso excluído');
});

it('does not ask for confirmation when the requested time conflicts with an existing event', function (): void {
    $user = agendaUserWithCalendar();
    $contact = agendaContact($user);
    $parser = Mockery::mock(AgendaIntentParser::class);
    $parser->shouldReceive('parse')->once()->andReturn([
        'intent' => 'create_event',
        'title' => 'Consulta',
        'start_at' => '2026-11-10T10:00:00-03:00',
        'end_at' => '2026-11-10T11:00:00-03:00',
        'time_zone' => 'America/Sao_Paulo',
        'attendees' => [],
    ]);
    app()->instance(AgendaIntentParser::class, $parser);

    $calendar = Mockery::mock(GoogleCalendarService::class);
    $calendar->shouldReceive('validateEventForConfirmation')->once()->andReturn([
        'summary' => 'Consulta',
        'start' => ['dateTime' => '2026-11-10T10:00:00-03:00', 'timeZone' => 'America/Sao_Paulo'],
        'end' => ['dateTime' => '2026-11-10T11:00:00-03:00', 'timeZone' => 'America/Sao_Paulo'],
        'attendees' => [],
    ]);
    $calendar->shouldReceive('hasConflict')->once()->andReturnTrue();
    $calendar->shouldReceive('suggestAvailableSlots')->once()->andReturn([]);
    $calendar->shouldNotReceive('createEvent');
    app()->instance(GoogleCalendarService::class, $calendar);

    $reply = app(AgendaCommandService::class)->handle('marca consulta dia 10 às 10', $user, $contact);

    expect($reply)->toContain('parece ocupado')
        ->and(data_get($contact->fresh()->conversation_state, 'mode'))->toBe('awaiting_clarification');
});

it('uses a complete new event request instead of appending stale clarification text', function (): void {
    $user = agendaUserWithCalendar();
    $contact = agendaContact($user, [
        'mode' => 'awaiting_clarification',
        'pending_intent' => 'agenda_create_event',
        'pending_payload' => ['original_message' => 'marque consulta para 30/10/2026'],
    ]);
    $parser = Mockery::mock(AgendaIntentParser::class);
    $parser->shouldReceive('parse')->once()->withArgs(function (string $message): bool {
        return $message === 'marque consulta TESTE InovaFinance para 30/10/2026 das 05:30 às 05:40';
    })->andReturn(['intent' => 'other']);
    app()->instance(AgendaIntentParser::class, $parser);

    $reply = app(AgendaCommandService::class)->handle(
        'marque consulta TESTE InovaFinance para 30/10/2026 das 05:30 às 05:40',
        $user,
        $contact,
    );

    expect($reply)->toContain('Posso consultar sua agenda')
        ->and(data_get($contact->fresh()->conversation_state, 'pending_intent'))->toBeNull();
});

it('preserves prior agenda context for a short qualified-time follow-up', function (): void {
    $user = agendaUserWithCalendar();
    $contact = agendaContact($user, [
        'mode' => 'awaiting_clarification',
        'pending_intent' => 'agenda_create_event',
        'pending_payload' => ['original_message' => 'marque consulta TESTE para 30/10/2026'],
    ]);
    $parser = Mockery::mock(AgendaIntentParser::class);
    $parser->shouldReceive('parse')->once()->withArgs(function (string $message): bool {
        return $message === 'marque consulta TESTE para 30/10/2026 às 5:30 da manhã';
    })->andReturn(['intent' => 'other']);
    app()->instance(AgendaIntentParser::class, $parser);

    app(AgendaCommandService::class)->handle('às 5:30 da manhã', $user, $contact);

    expect(data_get($contact->fresh()->conversation_state, 'pending_intent'))->toBe('agenda_create_event');
});

it('documents unambiguous Portuguese morning and afternoon time interpretation', function (): void {
    $instructions = app(\App\Ai\AgendaAgent::class)->instructions();

    expect((string) $instructions)->toContain('"5:30 da manhã" significa 05:30')
        ->and((string) $instructions)->toContain('não escolha AM/PM')
        ->and((string) $instructions)->toContain('check_availability');
});

it('recognizes natural-language agenda lookups for a weekday and the current week', function (string $message): void {
    $user = agendaUserWithCalendar();
    $contact = agendaContact($user);
    $parser = Mockery::mock(AgendaIntentParser::class);
    $parser->shouldReceive('parse')->once()->withArgs(fn (string $parsed): bool => $parsed === $message)
        ->andReturn(['intent' => 'list_events', 'period_start' => '2026-10-29T00:00:00-03:00', 'period_end' => '2026-10-30T00:00:00-03:00']);
    app()->instance(AgendaIntentParser::class, $parser);
    $calendar = Mockery::mock(GoogleCalendarService::class);
    $calendar->shouldReceive('listEvents')->once()->andReturn([]);
    app()->instance(GoogleCalendarService::class, $calendar);

    expect(app(AgendaCommandService::class)->handle($message, $user, $contact))
        ->toBe('Não encontrei compromissos nesse período.');
})->with([
    'o que tenho na quinta?',
    'quais são meus compromissos esta semana?',
]);

it('sorts agenda results chronologically and labels all-day events clearly', function (): void {
    $user = agendaUserWithCalendar();
    $contact = agendaContact($user);
    $parser = Mockery::mock(AgendaIntentParser::class);
    $parser->shouldReceive('parse')->once()->andReturn(['intent' => 'list_events']);
    app()->instance(AgendaIntentParser::class, $parser);
    $calendar = Mockery::mock(GoogleCalendarService::class);
    $calendar->shouldReceive('listEvents')->once()->andReturn([
        ['id' => 'late', 'summary' => 'Revisão', 'start' => ['dateTime' => '2026-10-29T15:00:00-03:00'], 'end' => ['dateTime' => '2026-10-29T16:00:00-03:00']],
        ['id' => 'all-day', 'summary' => 'Feriado', 'start' => ['date' => '2026-10-29'], 'end' => ['date' => '2026-10-30']],
        ['id' => 'early', 'summary' => 'Dentista', 'start' => ['dateTime' => '2026-10-29T09:30:00-03:00'], 'end' => ['dateTime' => '2026-10-29T10:00:00-03:00']],
    ]);
    app()->instance(GoogleCalendarService::class, $calendar);

    $reply = app(AgendaCommandService::class)->handle('o que tenho na quinta?', $user, $contact);

    expect(str_contains($reply, 'Dia todo — 29/10 — Feriado'))->toBeTrue()
        ->and(strpos($reply, 'Dentista'))->toBeLessThan(strpos($reply, 'Revisão'))
        ->and(str_contains($reply, '09:30–10:00'))->toBeTrue();
});

it('checks an exact availability interval without creating an event', function (): void {
    $user = agendaUserWithCalendar();
    $contact = agendaContact($user);
    $parser = Mockery::mock(AgendaIntentParser::class);
    $parser->shouldReceive('parse')->once()->andReturn([
        'intent' => 'check_availability',
        'start_at' => '2026-10-30T05:30:00-03:00',
        'end_at' => '2026-10-30T05:40:00-03:00',
        'time_zone' => 'America/Sao_Paulo',
    ]);
    app()->instance(AgendaIntentParser::class, $parser);
    $calendar = Mockery::mock(GoogleCalendarService::class);
    $calendar->shouldReceive('hasConflict')->once()->withArgs(function (User $actual, $start, $end, string $timezone) use ($user): bool {
        return $actual->is($user) && $start->format('H:i') === '05:30' && $end->format('H:i') === '05:40'
            && $timezone === 'America/Sao_Paulo';
    })->andReturnFalse();
    $calendar->shouldNotReceive('createEvent');
    app()->instance(GoogleCalendarService::class, $calendar);

    $reply = app(AgendaCommandService::class)->handle('estou livre dia 30/10/2026 das 5:30 da manhã às 5:40?', $user, $contact);

    expect($reply)->toContain('está livre')->toContain('Ainda não criei nenhum evento.');
});

it('asks for duration instead of guessing when availability has no precise interval', function (): void {
    $user = agendaUserWithCalendar();
    $contact = agendaContact($user);
    $parser = Mockery::mock(AgendaIntentParser::class);
    $parser->shouldNotReceive('parse');
    app()->instance(AgendaIntentParser::class, $parser);

    $reply = app(AgendaCommandService::class)->handle('estou livre amanhã de manhã?', $user, $contact);

    expect($reply)->toContain('quanto tempo o compromisso deve durar')
        ->and($reply)->toContain('Não vou presumir');
});

it('suggests bounded same-day alternatives when an exact interval is busy', function (): void {
    $user = agendaUserWithCalendar();
    $contact = agendaContact($user);
    $parser = Mockery::mock(AgendaIntentParser::class);
    $parser->shouldReceive('parse')->once()->andReturn([
        'intent' => 'check_availability',
        'start_at' => '2026-10-30T15:00:00-03:00',
        'end_at' => '2026-10-30T15:30:00-03:00',
        'time_zone' => 'America/Sao_Paulo',
    ]);
    app()->instance(AgendaIntentParser::class, $parser);
    $calendar = Mockery::mock(GoogleCalendarService::class);
    $calendar->shouldReceive('hasConflict')->twice()->andReturn(true, false);
    $calendar->shouldReceive('suggestAvailableSlots')->once()->withArgs(fn (User $actual, $start, $end, string $timezone): bool => $actual->is($user) && $start->format('H:i') === '15:00' && $end->format('H:i') === '15:30' && $timezone === 'America/Sao_Paulo')
        ->andReturn([
            ['start' => Carbon\CarbonImmutable::parse('2026-10-30T16:00:00-03:00'), 'end' => Carbon\CarbonImmutable::parse('2026-10-30T16:30:00-03:00')],
        ]);
    app()->instance(GoogleCalendarService::class, $calendar);

    $reply = app(AgendaCommandService::class)->handle('estou livre dia 30/10/2026 das 15:00 às 15:30?', $user, $contact);

    expect($reply)->toBeString()
        ->and(str_contains($reply, 'tem conflito'))->toBeTrue()
        ->and(str_contains($reply, '16:00–16:30'))->toBeTrue()
        ->and(str_contains($reply, 'Responda com o número'))->toBeTrue();

    $selected = app(AgendaCommandService::class)->handle('1', $user, $contact);
    expect(str_contains($selected, 'Esse horário está livre: 30/10/2026 16:00–16:30'))->toBeTrue()
        ->and(str_contains($selected, 'Ainda não criei nenhum evento.'))->toBeTrue()
        ->and(data_get($contact->fresh()->conversation_state, 'pending_intent'))->toBeNull();
});

it('requires confirmation after selecting an alternative for a new event', function (): void {
    $user = agendaUserWithCalendar();
    $contact = agendaContact($user);
    $parser = Mockery::mock(AgendaIntentParser::class);
    $parser->shouldReceive('parse')->once()->andReturn([
        'intent' => 'create_event',
        'title' => 'Consulta de teste',
        'start_at' => '2026-10-30T15:00:00-03:00',
        'end_at' => '2026-10-30T15:30:00-03:00',
        'time_zone' => 'America/Sao_Paulo',
        'attendees' => [],
    ]);
    app()->instance(AgendaIntentParser::class, $parser);
    $calendar = Mockery::mock(GoogleCalendarService::class);
    $calendar->shouldReceive('hasConflict')->times(3)->andReturn(true, false, false);
    $calendar->shouldReceive('suggestAvailableSlots')->once()->andReturn([
        ['start' => Carbon\CarbonImmutable::parse('2026-10-30T16:00:00-03:00'), 'end' => Carbon\CarbonImmutable::parse('2026-10-30T16:30:00-03:00')],
    ]);
    $calendar->shouldReceive('validateEventForConfirmation')->twice()->andReturn(
        [
            'summary' => 'Consulta de teste',
            'start' => ['dateTime' => '2026-10-30T15:00:00-03:00', 'timeZone' => 'America/Sao_Paulo'],
            'end' => ['dateTime' => '2026-10-30T15:30:00-03:00', 'timeZone' => 'America/Sao_Paulo'],
            'attendees' => [],
        ],
        [
            'summary' => 'Consulta de teste',
            'start' => ['dateTime' => '2026-10-30T16:00:00-03:00', 'timeZone' => 'America/Sao_Paulo'],
            'end' => ['dateTime' => '2026-10-30T16:30:00-03:00', 'timeZone' => 'America/Sao_Paulo'],
            'attendees' => [],
        ],
    );
    $calendar->shouldReceive('createEvent')->once()->andReturn(['htmlLink' => 'https://calendar.google.test/event']);
    app()->instance(GoogleCalendarService::class, $calendar);

    $service = app(AgendaCommandService::class);
    expect($service->handle('marque consulta de teste para 30/10/2026 das 15:00 às 15:30', $user, $contact))
        ->toContain('Responda com o número');
    expect($service->handle('1', $user, $contact))->toContain('Confirma criar este evento')
        ->and(data_get($contact->fresh()->conversation_state, 'mode'))->toBe('awaiting_confirmation');
    expect($service->handle('sim', $user, $contact))->toContain('Compromisso criado');
});

it('explains Google permission failures without exposing provider payloads', function (): void {
    $user = agendaUserWithCalendar();
    $contact = agendaContact($user);
    $parser = Mockery::mock(AgendaIntentParser::class);
    $parser->shouldReceive('parse')->once()->andReturn(['intent' => 'list_events']);
    app()->instance(AgendaIntentParser::class, $parser);
    $calendar = Mockery::mock(GoogleCalendarService::class);
    $calendar->shouldReceive('listEvents')->once()->andThrow(new App\Exceptions\GoogleCalendarApiException(403, 'accessNotConfigured'));
    app()->instance(GoogleCalendarService::class, $calendar);

    $reply = app(AgendaCommandService::class)->handle('mostre minha agenda amanhã', $user, $contact);

    expect($reply)->toContain('API do Google Calendar não está ativada')
        ->and($reply)->not->toContain('PERMISSION_DENIED');
});

it('distinguishes an AI provider rate limit from an unrecognized agenda request', function (): void {
    $user = agendaUserWithCalendar();
    $contact = agendaContact($user);
    $parser = Mockery::mock(AgendaIntentParser::class);
    $parser->shouldReceive('parse')->once()->andThrow(Laravel\Ai\Exceptions\RateLimitedException::forProvider('groq'));
    app()->instance(AgendaIntentParser::class, $parser);

    $reply = app(AgendaCommandService::class)->handle('marque consulta amanhã às 10', $user, $contact);

    expect($reply)->toContain('temporariamente ocupado')
        ->and(str_contains($reply, 'Não consegui entender'))->toBeFalse();
});

it('clears an abandoned agenda clarification and lets a financial message continue', function (): void {
    $user = agendaUserWithCalendar();
    $contact = agendaContact($user, [
        'mode' => 'awaiting_clarification',
        'pending_intent' => 'agenda_create_event',
        'pending_payload' => ['original_message' => 'marque uma consulta'],
    ]);

    expect(app(AgendaCommandService::class)->handle('gastei 87 na farmácia', $user, $contact))->toBeNull()
        ->and(data_get($contact->fresh()->conversation_state, 'pending_intent'))->toBeNull();
});
