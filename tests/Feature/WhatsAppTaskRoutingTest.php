<?php

use App\Models\GoogleCalendarConnection;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\GoogleTasksService;
use App\Services\Meetings\MeetingCommandService;
use App\Services\TaskIntentParser;
use App\Services\WhatsApp\AgendaCommandService;
use App\Services\WhatsApp\ConversationOrchestrator;
use App\Services\WhatsApp\ConversationStateService;
use App\Services\WhatsApp\TaskCommandService;
use App\Services\WhatsApp\Resolvers\PreflightMessageResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;

uses(RefreshDatabase::class);

function taskUserWithGoogleTasks(): User
{
    $user = User::factory()->create();
    GoogleCalendarConnection::create([
        'user_id' => $user->id,
        'google_email' => 'tasks@example.test',
        'refresh_token' => Crypt::encryptString('tasks-refresh-token'),
        'scopes' => ['https://www.googleapis.com/auth/tasks'],
        'calendar_id' => 'primary',
        'connected_at' => now(),
    ]);

    return $user;
}

function taskContact(User $user, array $state = []): WhatsAppContact
{
    return WhatsAppContact::factory()->create([
        'user_id' => $user->id,
        'phone_number' => '551100000'.str_pad((string) (WhatsAppContact::query()->count() + 1), 4, '0', STR_PAD_LEFT),
        'conversation_state' => $state,
    ]);
}

it('routes task requests before the financial agent while keeping financial text on its existing path', function (): void {
    $user = User::factory()->create();
    $contact = taskContact($user);
    $task = Mockery::mock(TaskCommandService::class);
    $task->shouldReceive('handle')->once()->with('cria tarefa ligar para Ana', $user, $contact)->andReturn('Confirma registrar?');
    $task->shouldReceive('handle')->once()->with('gastei 80 no mercado', $user, Mockery::type(WhatsAppContact::class))->andReturnNull();
    app()->instance(TaskCommandService::class, $task);

    $meeting = Mockery::mock(MeetingCommandService::class);
    $meeting->shouldReceive('handle')->twice()->andReturnNull();
    app()->instance(MeetingCommandService::class, $meeting);

    $agenda = Mockery::mock(AgendaCommandService::class);
    $agenda->shouldReceive('handle')->once()->with('gastei 80 no mercado', $user, Mockery::type(WhatsAppContact::class))->andReturnNull();
    app()->instance(AgendaCommandService::class, $agenda);

    $routed = app(ConversationOrchestrator::class)->beforeAI('cria tarefa ligar para Ana', $user, $contact);
    expect($routed)->toMatchArray(['handled' => true, 'classification' => 'task_command', 'domain' => 'tasks']);

    $contact2 = taskContact($user);
    expect(app(ConversationOrchestrator::class)->beforeAI('gastei 80 no mercado', $user, $contact2))->toBeNull();
});

it('requires confirmation before submitting a Google Task', function (): void {
    $user = taskUserWithGoogleTasks();
    $contact = taskContact($user);
    $parser = Mockery::mock(TaskIntentParser::class);
    $parser->shouldReceive('parse')->once()->andReturn([
        'intent' => 'create_task',
        'title' => 'Ligar para Ana',
        'notes' => null,
        'due_date' => '2026-11-10',
    ]);
    app()->instance(TaskIntentParser::class, $parser);

    $tasks = Mockery::mock(GoogleTasksService::class);
    $tasks->shouldReceive('createTask')->once()->with($user, [
        'title' => 'Ligar para Ana',
        'due' => '2026-11-10',
    ], '@default')->andReturn(['id' => 'task-123', 'title' => 'Ligar para Ana']);
    app()->instance(GoogleTasksService::class, $tasks);

    $service = app(TaskCommandService::class);
    expect($service->handle('cria tarefa ligar para Ana até 10/11/2026', $user, $contact))->toContain('Responda *sim*');
    expect(data_get($contact->fresh()->conversation_state, 'pending_intent'))->toBe('google_task_create');

    expect($service->handle('sim', $user, $contact))->toContain('Tarefa registrada');
    expect(data_get($contact->fresh()->conversation_state, 'pending_intent'))->toBeNull();
});

it('does not retry an uncertain task submission and risk creating a duplicate', function (): void {
    $user = taskUserWithGoogleTasks();
    $contact = taskContact($user, [
        'mode' => 'awaiting_confirmation',
        'pending_intent' => 'google_task_create',
        'pending_payload' => [
            'task' => ['title' => 'Enviar proposta', 'due_date' => null],
            'idempotency_key' => 'fixed-key',
            'submission_status' => 'unknown',
        ],
    ]);
    $tasks = Mockery::mock(GoogleTasksService::class);
    $tasks->shouldNotReceive('createTask');
    app()->instance(GoogleTasksService::class, $tasks);

    expect(app(TaskCommandService::class)->handle('sim', $user, $contact))->toContain('reenviar esta tarefa automaticamente');
});

it('allows cancelling a pending task after Google authorization is unavailable', function (): void {
    $user = User::factory()->create();
    $contact = taskContact($user, [
        'mode' => 'awaiting_confirmation',
        'pending_intent' => 'google_task_create',
        'pending_payload' => ['task' => ['title' => 'Enviar proposta']],
    ]);

    $reply = app(TaskCommandService::class)->handle('não', $user, $contact);

    expect($reply)->toBe('Tudo bem, não criei essa tarefa ou projeto.')
        ->and(data_get($contact->fresh()->conversation_state, 'pending_intent'))->toBeNull();
});

it('preserves pending actions owned by other conversation flows', function (): void {
    $user = User::factory()->create();
    $contact = taskContact($user, [
        'mode' => 'awaiting_confirmation',
        'pending_intent' => 'confirm_large_transaction',
        'pending_payload' => ['transaction_data' => ['amount' => 125.00]],
    ]);
    $reply = app(TaskCommandService::class)->handle('crie uma tarefa para ligar para Ana', $user, $contact);

    expect($reply)->toContain('outra confirmação')
        ->and(data_get($contact->fresh()->conversation_state, 'pending_intent'))->toBe('confirm_large_transaction');
});

it('updates a matching task only after explicit confirmation', function (): void {
    $user = taskUserWithGoogleTasks();
    $contact = taskContact($user);
    $parser = Mockery::mock(TaskIntentParser::class);
    $parser->shouldReceive('parse')->once()->andReturn([
        'intent' => 'update_task',
        'target_query' => 'Enviar proposta',
        'new_title' => 'Enviar proposta revisada',
        'due_date' => '2026-11-12',
    ]);
    app()->instance(TaskIntentParser::class, $parser);

    $tasks = Mockery::mock(GoogleTasksService::class);
    $tasks->shouldReceive('listTasks')->once()->with($user, 100, '@default')->andReturn([
        ['id' => 'task-456', 'title' => 'Enviar proposta'],
    ]);
    $tasks->shouldReceive('updateTask')->once()->with($user, 'task-456', [
        'title' => 'Enviar proposta revisada',
        'due' => '2026-11-12',
    ], '@default')->andReturn(['id' => 'task-456', 'title' => 'Enviar proposta revisada']);
    app()->instance(GoogleTasksService::class, $tasks);

    $service = app(TaskCommandService::class);
    expect($service->handle('atualize a tarefa Enviar proposta', $user, $contact))->toContain('Responda *sim*');
    expect(data_get($contact->fresh()->conversation_state, 'pending_intent'))->toBe('google_task_update');
    expect($service->handle('sim', $user, $contact))->toContain('Tarefa atualizada');
});

it('completes a uniquely matched task only after confirmation', function (): void {
    $user = taskUserWithGoogleTasks();
    $contact = taskContact($user);
    $parser = Mockery::mock(TaskIntentParser::class);
    $parser->shouldReceive('parse')->once()->andReturn([
        'intent' => 'complete_task',
        'target_query' => 'Ligar para Ana',
    ]);
    app()->instance(TaskIntentParser::class, $parser);

    $tasks = Mockery::mock(GoogleTasksService::class);
    $tasks->shouldReceive('listTasks')->once()->with($user, 100, '@default')->andReturn([
        ['id' => 'task-789', 'title' => 'Ligar para Ana'],
    ]);
    $tasks->shouldReceive('completeTask')->once()->with($user, 'task-789', '@default')
        ->andReturn(['id' => 'task-789', 'title' => 'Ligar para Ana']);
    app()->instance(GoogleTasksService::class, $tasks);

    $service = app(TaskCommandService::class);
    expect($service->handle('concluir tarefa Ligar para Ana', $user, $contact))->toContain('Responda *sim*');
    expect($service->handle('sim', $user, $contact))->toContain('marcada como concluída');
});

it('deletes a uniquely matched task only after explicit confirmation', function (): void {
    $user = taskUserWithGoogleTasks();
    $contact = taskContact($user);
    $parser = Mockery::mock(TaskIntentParser::class);
    $parser->shouldReceive('parse')->once()->andReturn([
        'intent' => 'delete_task',
        'target_query' => 'Revisar contrato',
        'project_title' => 'Lançamento',
    ]);
    app()->instance(TaskIntentParser::class, $parser);

    $tasks = Mockery::mock(GoogleTasksService::class);
    $tasks->shouldReceive('listProjectLists')->once()->with($user)->andReturn([
        ['id' => 'project-2', 'title' => 'Lançamento'],
    ]);
    $tasks->shouldReceive('listTasks')->once()->with($user, 100, 'project-2')->andReturn([
        ['id' => 'task-456', 'title' => 'Revisar contrato'],
    ]);
    $tasks->shouldReceive('deleteTask')->once()->with($user, 'task-456', 'project-2');
    app()->instance(GoogleTasksService::class, $tasks);

    $service = app(TaskCommandService::class);
    expect($service->handle('exclua a tarefa Revisar contrato do projeto Lançamento', $user, $contact))
        ->toContain('excluir permanentemente')
        ->toContain('Responda *sim*');
    expect($service->handle('sim', $user, $contact))->toContain('Tarefa excluída do Google Tasks');
});

it('does not delete a task when the target title is ambiguous', function (): void {
    $user = taskUserWithGoogleTasks();
    $contact = taskContact($user);
    $parser = Mockery::mock(TaskIntentParser::class);
    $parser->shouldReceive('parse')->once()->andReturn([
        'intent' => 'delete_task',
        'target_query' => 'proposta',
        'project_title' => null,
    ]);
    app()->instance(TaskIntentParser::class, $parser);

    $tasks = Mockery::mock(GoogleTasksService::class);
    $tasks->shouldReceive('listTasks')->once()->andReturn([
        ['id' => 'task-1', 'title' => 'Enviar proposta'],
        ['id' => 'task-2', 'title' => 'Revisar proposta'],
    ]);
    $tasks->shouldNotReceive('deleteTask');
    app()->instance(GoogleTasksService::class, $tasks);

    expect(app(TaskCommandService::class)->handle('exclua tarefa proposta', $user, $contact))
        ->toContain('mais de uma tarefa');
});

it('does not modify a task when the title is ambiguous', function (): void {
    $user = taskUserWithGoogleTasks();
    $contact = taskContact($user);
    $parser = Mockery::mock(TaskIntentParser::class);
    $parser->shouldReceive('parse')->once()->andReturn([
        'intent' => 'complete_task',
        'target_query' => 'proposta',
    ]);
    app()->instance(TaskIntentParser::class, $parser);

    $tasks = Mockery::mock(GoogleTasksService::class);
    $tasks->shouldReceive('listTasks')->once()->andReturn([
        ['id' => 'task-1', 'title' => 'Enviar proposta'],
        ['id' => 'task-2', 'title' => 'Revisar proposta'],
    ]);
    $tasks->shouldNotReceive('completeTask');
    app()->instance(GoogleTasksService::class, $tasks);

    expect(app(TaskCommandService::class)->handle('concluir tarefa proposta', $user, $contact))
        ->toContain('mais de uma tarefa');
});

it('creates a project only after confirmation and represents it as a Google Tasks list', function (): void {
    $user = taskUserWithGoogleTasks();
    $contact = taskContact($user);
    $parser = Mockery::mock(TaskIntentParser::class);
    $parser->shouldReceive('parse')->once()->andReturn([
        'intent' => 'create_project',
        'project_title' => 'Lançamento do site',
    ]);
    app()->instance(TaskIntentParser::class, $parser);

    $tasks = Mockery::mock(GoogleTasksService::class);
    $tasks->shouldReceive('listProjectLists')->twice()->with($user)->andReturn([]);
    $tasks->shouldReceive('createProjectList')->once()->with($user, 'Lançamento do site')
        ->andReturn(['id' => 'project-1', 'title' => 'Lançamento do site']);
    app()->instance(GoogleTasksService::class, $tasks);

    $service = app(TaskCommandService::class);
    expect($service->handle('crie um projeto chamado Lançamento do site', $user, $contact))->toContain('Confirma?');
    expect($service->handle('sim', $user, $contact))->toContain('Projeto criado como lista');
});

it('renames a unique project only after confirmation and keeps its existing task list id', function (): void {
    $user = taskUserWithGoogleTasks();
    $contact = taskContact($user);
    $parser = Mockery::mock(TaskIntentParser::class);
    $parser->shouldReceive('parse')->once()->andReturn([
        'intent' => 'rename_project',
        'project_title' => 'Lançamento',
        'new_title' => 'Lançamento do produto',
    ]);
    app()->instance(TaskIntentParser::class, $parser);

    $tasks = Mockery::mock(GoogleTasksService::class);
    $tasks->shouldReceive('listProjectLists')->times(4)->andReturn(
        [['id' => 'project-7', 'title' => 'Lançamento']],
        [],
        [['id' => 'project-7', 'title' => 'Lançamento']],
        [],
    );
    $tasks->shouldReceive('renameProjectList')->once()->with($user, 'project-7', 'Lançamento do produto')
        ->andReturn(['id' => 'project-7', 'title' => 'Lançamento do produto']);
    app()->instance(GoogleTasksService::class, $tasks);

    $service = app(TaskCommandService::class);
    expect($service->handle('renomeie o projeto Lançamento para Lançamento do produto', $user, $contact))
        ->toContain('As tarefas continuarão nessa lista')
        ->toContain('Responda *sim*');
    expect($service->handle('sim', $user, $contact))->toContain('Projeto renomeado no Google Tasks');
});

it('creates a task in the explicitly selected project list', function (): void {
    $user = taskUserWithGoogleTasks();
    $contact = taskContact($user);
    $parser = Mockery::mock(TaskIntentParser::class);
    $parser->shouldReceive('parse')->once()->andReturn([
        'intent' => 'create_task',
        'title' => 'Revisar contrato',
        'notes' => null,
        'due_date' => null,
        'project_title' => 'Lançamento do site',
    ]);
    app()->instance(TaskIntentParser::class, $parser);

    $tasks = Mockery::mock(GoogleTasksService::class);
    $tasks->shouldReceive('listProjectLists')->once()->with($user)->andReturn([
        ['id' => 'project-1', 'title' => 'Lançamento do site'],
    ]);
    $tasks->shouldReceive('createTask')->once()->with($user, ['title' => 'Revisar contrato'], 'project-1')
        ->andReturn(['id' => 'task-456', 'title' => 'Revisar contrato']);
    app()->instance(GoogleTasksService::class, $tasks);

    $service = app(TaskCommandService::class);
    expect($service->handle('crie tarefa Revisar contrato no projeto Lançamento do site', $user, $contact))
        ->toContain('Projeto: Lançamento do site');
    expect($service->handle('sim', $user, $contact))->toContain('Tarefa registrada');
});

it('summarizes pending completed and overdue tasks in a uniquely matched project', function (): void {
    $user = taskUserWithGoogleTasks();
    $contact = taskContact($user);
    $parser = Mockery::mock(TaskIntentParser::class);
    $parser->shouldReceive('parse')->once()->andReturn([
        'intent' => 'summarize_project',
        'project_title' => 'Lançamento',
    ]);
    app()->instance(TaskIntentParser::class, $parser);

    $tasks = Mockery::mock(GoogleTasksService::class);
    $tasks->shouldReceive('listProjectLists')->once()->andReturn([
        ['id' => 'project-8', 'title' => 'Lançamento'],
    ]);
    $tasks->shouldReceive('listTasksForSummary')->once()->with($user, 'project-8')->andReturn([
        'tasks' => [
            ['id' => 'late', 'title' => 'Enviar contrato', 'status' => 'needsAction', 'due' => '2020-01-02T00:00:00.000Z'],
            ['id' => 'open', 'title' => 'Preparar apresentação', 'status' => 'needsAction', 'due' => '2099-01-02T00:00:00.000Z'],
            ['id' => 'done', 'title' => 'Aprovar orçamento', 'status' => 'completed'],
        ],
        'truncated' => false,
    ]);
    app()->instance(GoogleTasksService::class, $tasks);

    $reply = app(TaskCommandService::class)->handle('faça um resumo do projeto Lançamento', $user, $contact);

    expect($reply)->toContain('Pendentes: 2')
        ->toContain('Concluídas: 1')
        ->toContain('Atrasadas: 1')
        ->toContain('Enviar contrato')
        ->toContain('Preparar apresentação');
});

it('moves a task between lists only after explicit confirmation', function (): void {
    $user = taskUserWithGoogleTasks();
    $contact = taskContact($user);
    $parser = Mockery::mock(TaskIntentParser::class);
    $parser->shouldReceive('parse')->once()->andReturn([
        'intent' => 'move_task',
        'target_query' => 'Enviar proposta',
        'source_project_title' => null,
        'project_title' => 'Lançamento do site',
    ]);
    app()->instance(TaskIntentParser::class, $parser);

    $tasks = Mockery::mock(GoogleTasksService::class);
    $tasks->shouldReceive('listProjectLists')->once()->with($user)->andReturn([
        ['id' => 'project-1', 'title' => 'Lançamento do site'],
    ]);
    $tasks->shouldReceive('listTasks')->once()->with($user, 100, '@default')->andReturn([
        ['id' => 'task-999', 'title' => 'Enviar proposta'],
    ]);
    $tasks->shouldReceive('moveTask')->once()->with($user, 'task-999', '@default', 'project-1')
        ->andReturn(['id' => 'task-999', 'title' => 'Enviar proposta']);
    app()->instance(GoogleTasksService::class, $tasks);

    $service = app(TaskCommandService::class);
    expect($service->handle('mova tarefa Enviar proposta para projeto Lançamento do site', $user, $contact))
        ->toContain('Confirma mover');
    expect($service->handle('sim', $user, $contact))->toContain('Tarefa movida para o projeto');
});
