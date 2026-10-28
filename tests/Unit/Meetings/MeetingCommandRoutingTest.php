<?php

uses(Tests\TestCase::class);

use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\Meetings\MeetingCommandService;
use App\Services\Meetings\MeetingUrlParser;
use App\Services\Meetings\VexaApiClient;
use App\Services\WhatsApp\ConversationOrchestrator;
use App\Services\WhatsApp\ConversationStateService;
use App\Services\WhatsApp\AgendaCommandService;
use App\Services\WhatsApp\TaskCommandService;
use App\Services\WhatsApp\Resolvers\PreflightMessageResolver;
use Illuminate\Support\Facades\Http;

function meetingOrchestrator(MeetingCommandService $commands): ConversationOrchestrator
{
    return new ConversationOrchestrator(
        Mockery::mock(ConversationStateService::class),
        Mockery::mock(PreflightMessageResolver::class),
        $commands,
        Mockery::mock(AgendaCommandService::class),
        Mockery::mock(TaskCommandService::class),
    );
}

it('intercepts meeting commands before general preflight when Vexa is enabled', function (string $message) {
    $user = new User(['id' => 7]);
    $contact = new WhatsAppContact(['id' => 9, 'user_id' => 7]);
    $commands = Mockery::mock(MeetingCommandService::class);
    $commands->shouldReceive('handle')->once()->with($message, $user, $contact)->andReturn('resposta de reuniões');

    $result = meetingOrchestrator($commands)->beforeAI($message, $user, $contact);

    expect($result)->toMatchArray([
        'handled' => true,
        'reply' => 'resposta de reuniões',
        'classification' => 'meeting_command',
        'domain' => 'meetings',
    ]);
})->with([
    'reunião https://meet.google.com/abc-defg-hij participantes avisados e autorizo',
    'reuniao https://meet.google.com/abc-defg-hij participantes avisados e autorizo',
    'status da reunião',
    'status da reuniao',
    'resumo da reunião',
    'resumo da reuniao',
]);

it('returns a handled unavailable reply for a recognized meeting command when Vexa is disabled', function (string $message) {
    config(['vexa.enabled' => false]);
    Http::fake();

    $user = new User(['id' => 7]);
    $contact = new WhatsAppContact(['id' => 9, 'user_id' => 7]);
    $commands = new MeetingCommandService(new MeetingUrlParser(), new VexaApiClient());

    $result = meetingOrchestrator($commands)->beforeAI($message, $user, $contact);

    expect($result['handled'])->toBeTrue()
        ->and($result['classification'])->toBe('meeting_command')
        ->and($result['reply'])->toContain('indisponível');
    Http::assertNothingSent();
})->with([
    'reunião https://meet.google.com/abc-defg-hij participantes avisados e autorizo',
    'reuniao https://meet.google.com/abc-defg-hij participantes avisados e autorizo',
]);

it('preserves the original meeting URL while recognizing normalized command spacing', function () {
    config(['vexa.enabled' => true, 'vexa.base_url' => 'https://vexa.test', 'vexa.api_key' => 'test-key']);
    $url = 'https://meet.google.com/ABC-DefG-hij?authuser=User@Example.com';
    $parser = Mockery::mock(MeetingUrlParser::class);
    $parser->shouldReceive('parse')->once()->with($url)->andThrow(new InvalidArgumentException('link inválido para este teste'));
    $service = new MeetingCommandService($parser, Mockery::mock(VexaApiClient::class));

    $reply = $service->handle("Reunião   {$url}   participantes   avisados e autorizo", new User(['id' => 7]), new WhatsAppContact(['id' => 9]));

    expect($reply)->toBe('link inválido para este teste');
});

it('leaves ordinary financial messages for the existing preflight and agent flow', function () {
    $user = new User(['id' => 7]);
    $contact = new WhatsAppContact(['id' => 9, 'user_id' => 7]);
    $commands = Mockery::mock(MeetingCommandService::class);
    $commands->shouldReceive('handle')->once()->andReturnNull();
    $state = Mockery::mock(ConversationStateService::class);
    $state->shouldReceive('getState')->once()->with($contact)->andReturn(['mode' => 'idle']);
    $resolver = Mockery::mock(PreflightMessageResolver::class);
    $resolver->shouldNotReceive('resolve');
    $agenda = Mockery::mock(AgendaCommandService::class);
    $agenda->shouldReceive('handle')->once()->with('quanto gastei com mercado?', $user, $contact)->andReturnNull();
    $tasks = Mockery::mock(TaskCommandService::class);
    $tasks->shouldReceive('handle')->once()->with('quanto gastei com mercado?', $user, $contact)->andReturnNull();

    expect((new ConversationOrchestrator($state, $resolver, $commands, $agenda, $tasks))
        ->beforeAI('quanto gastei com mercado?', $user, $contact))->toBeNull();
});
