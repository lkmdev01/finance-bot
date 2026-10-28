<?php

use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\Meetings\MeetingCommandService;
use App\Services\WhatsApp\ConversationOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves agenda and task handlers from the container before the financial agent', function (): void {
    $user = User::factory()->create();
    $contact = WhatsAppContact::factory()->create([
        'user_id' => $user->id,
        'phone_number' => '5511999912345',
    ]);

    $meetings = Mockery::mock(MeetingCommandService::class);
    $meetings->shouldReceive('handle')->twice()->andReturnNull();
    app()->instance(MeetingCommandService::class, $meetings);

    $orchestrator = app(ConversationOrchestrator::class);

    $agenda = $orchestrator->beforeAI('quero ver minha agenda esta semana', $user, $contact);
    expect($agenda)->toMatchArray([
        'handled' => true,
        'classification' => 'agenda_command',
        'domain' => 'agenda',
    ])->and($agenda['reply'])->toContain('conecte o Google Calendar');

    $tasks = $orchestrator->beforeAI('liste minhas tarefas pendentes', $user, $contact);
    expect($tasks)->toMatchArray([
        'handled' => true,
        'classification' => 'task_command',
        'domain' => 'tasks',
    ])->and($tasks['reply'])->toContain('conecte sua conta Google');
});
