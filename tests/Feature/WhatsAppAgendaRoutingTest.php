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
    $calendar->shouldNotReceive('createEvent');
    app()->instance(GoogleCalendarService::class, $calendar);

    $reply = app(AgendaCommandService::class)->handle('marca consulta dia 10 às 10', $user, $contact);

    expect($reply)->toContain('parece ocupado')
        ->and(data_get($contact->fresh()->conversation_state, 'mode'))->toBe('awaiting_clarification');
});
