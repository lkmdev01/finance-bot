<?php

use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\Meetings\MeetingCommandService;
use App\Services\WhatsApp\AgendaCommandService;
use App\Services\WhatsApp\ConversationOrchestrator;
use App\Services\WhatsApp\ConversationStateService;
use App\Services\WhatsApp\ResearchCommandService;
use App\Services\WhatsApp\Resolvers\PreflightMessageResolver;
use App\Services\WhatsApp\TaskCommandService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('routes the anonymized cross-domain scenario matrix to the expected domain', function (array $scenario): void {
    $user = new User(['id' => 41, 'name' => 'Pessoa Exemplo']);
    $contact = new WhatsAppContact(['id' => 51, 'user_id' => 41]);
    $services = [
        'research' => Mockery::mock(ResearchCommandService::class),
        'meetings' => Mockery::mock(MeetingCommandService::class),
        'tasks' => Mockery::mock(TaskCommandService::class),
        'agenda' => Mockery::mock(AgendaCommandService::class),
    ];
    $domainOrder = ['research', 'meetings', 'tasks', 'agenda'];
    $targetIndex = array_search($scenario['domain'], $domainOrder, true);

    foreach ($domainOrder as $index => $domain) {
        if ($scenario['domain'] !== 'finance' && $index > $targetIndex) {
            $services[$domain]->shouldNotReceive('handle');

            continue;
        }

        $services[$domain]
            ->shouldReceive('handle')
            ->once()
            ->with($scenario['message'], $user, $contact)
            ->andReturn($scenario['domain'] !== 'finance' && $index === $targetIndex ? $scenario['reply'] : null);
    }

    $state = Mockery::mock(ConversationStateService::class);
    if ($scenario['domain'] === 'finance') {
        $state->shouldReceive('getState')->once()->with($contact)->andReturn(['mode' => 'idle']);
    }
    $resolver = Mockery::mock(PreflightMessageResolver::class);
    $resolver->shouldNotReceive('resolve');

    $orchestrator = new ConversationOrchestrator(
        $state,
        $resolver,
        $services['meetings'],
        $services['agenda'],
        $services['tasks'],
        $services['research'],
    );

    $result = $orchestrator->beforeAI($scenario['message'], $user, $contact);

    if ($scenario['domain'] === 'finance') {
        // Null is the handoff contract: WhatsAppMessageProcessor continues to FinancialAgent.
        expect($result)->toBeNull();

        return;
    }

    expect($result)
        ->toMatchArray([
            'handled' => true,
            'domain' => $scenario['domain'],
            'reply' => $scenario['reply'],
        ]);
})->with('assistant_quality_scenarios');

it('asks for missing event details instead of guessing', function (): void {
    config(['services.google_calendar.scopes' => [
        'https://www.googleapis.com/auth/calendar.events.owned',
        'https://www.googleapis.com/auth/calendar.events.freebusy',
    ]]);
    $user = User::factory()->create();
    App\Models\GoogleCalendarConnection::query()->create([
        'user_id' => $user->id,
        'google_email' => 'calendar@example.test',
        'refresh_token' => Illuminate\Support\Facades\Crypt::encryptString('fixture-refresh-token'),
        'scopes' => config('services.google_calendar.scopes'),
        'calendar_id' => 'primary',
        'connected_at' => now(),
    ]);
    $contact = WhatsAppContact::factory()->create([
        'user_id' => $user->id,
        'phone_number' => '5511999000001',
        'conversation_state' => [],
    ]);

    $parser = Mockery::mock(\App\Services\AgendaIntentParser::class);
    $parser->shouldReceive('parse')->once()->andReturn([
        'intent' => 'create_event',
        'title' => 'Dentista',
        'start_at' => '2026-11-10T10:00:00-03:00',
        'end_at' => null,
        'time_zone' => 'America/Sao_Paulo',
        'attendees' => [],
        'recurrence' => [],
        'reminder_minutes' => null,
        'disable_reminder' => false,
    ]);
    app()->instance(\App\Services\AgendaIntentParser::class, $parser);
    $calendar = Mockery::mock(\App\Services\GoogleCalendarService::class);
    $calendar->shouldNotReceive('validateEventForConfirmation');
    app()->instance(\App\Services\GoogleCalendarService::class, $calendar);

    $reply = app(AgendaCommandService::class)->handle('marque dentista dia 10/11 às 10', $user, $contact);

    expect($reply)->toContain('preciso do título, da data e dos horários de início e fim')
        ->and(data_get($contact->fresh()->conversation_state, 'pending_intent'))->toBe('agenda_create_event');
});

it('asks what a task is before creating an incomplete request', function (): void {
    $user = User::factory()->create();
    App\Models\GoogleCalendarConnection::query()->create([
        'user_id' => $user->id,
        'google_email' => 'tasks@example.test',
        'refresh_token' => Illuminate\Support\Facades\Crypt::encryptString('fixture-refresh-token'),
        'scopes' => ['https://www.googleapis.com/auth/tasks'],
        'calendar_id' => 'primary',
        'connected_at' => now(),
    ]);
    $contact = WhatsAppContact::factory()->create([
        'user_id' => $user->id,
        'phone_number' => '5511999000002',
        'conversation_state' => [],
    ]);

    $parser = Mockery::mock(\App\Services\TaskIntentParser::class);
    $parser->shouldReceive('parse')->once()->andReturn([
        'intent' => 'create_task',
        'title' => null,
        'notes' => null,
        'due_date' => null,
        'project_title' => null,
    ]);
    app()->instance(\App\Services\TaskIntentParser::class, $parser);
    $tasks = Mockery::mock(\App\Services\GoogleTasksService::class);
    $tasks->shouldNotReceive('createTask');
    app()->instance(\App\Services\GoogleTasksService::class, $tasks);

    $reply = app(TaskCommandService::class)->handle('crie uma tarefa', $user, $contact);

    expect($reply)->toBe('O que exatamente você quer registrar como tarefa?')
        ->and(data_get($contact->fresh()->conversation_state, 'pending_intent'))->toBe('google_task_create');
});

it('explains the meeting consent command when details are missing', function (): void {
    $service = new MeetingCommandService(
        new \App\Services\Meetings\MeetingUrlParser,
        Mockery::mock(\App\Services\Meetings\VexaApiClient::class),
    );

    $reply = $service->handle('reunião', new User(['id' => 41]), new WhatsAppContact(['id' => 51, 'user_id' => 41]));

    expect($reply)->toContain('participantes avisados e autorizo')
        ->and($reply)->toContain('gravará a reunião')
        ->and($reply)->toContain('o áudio será transcrito posteriormente');
});

dataset('assistant_quality_scenarios', function (): array {
    $scenarios = require __DIR__.'/../Fixtures/assistant_quality_scenarios.php';

    return collect($scenarios)->mapWithKeys(fn (array $scenario): array => [
        $scenario['name'] => [$scenario],
    ])->all();
});
