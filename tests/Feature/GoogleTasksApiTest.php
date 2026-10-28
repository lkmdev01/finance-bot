<?php

use App\Models\GoogleCalendarConnection;
use App\Models\User;
use App\Services\GoogleCalendarOAuthService;
use App\Services\GoogleTasksService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function googleTasksUser(): User
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

beforeEach(function (): void {
    config([
        'services.google_calendar.client_id' => 'test-client',
        'services.google_calendar.client_secret' => 'test-secret',
    ]);
});

it('creates a task in the default Google Tasks list and preserves a day-only due date', function (): void {
    $user = googleTasksUser();
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token-test', 'expires_in' => 3600]),
        'tasks.googleapis.com/tasks/v1/lists/@default/tasks' => Http::response(['id' => 'task-123', 'title' => 'Enviar proposta'], 200),
    ]);

    $task = app(GoogleTasksService::class)->createTask($user, [
        'title' => 'Enviar proposta',
        'due' => '2026-11-10',
    ]);

    expect($task['id'])->toBe('task-123');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_contains($request->url(), '/lists/@default/tasks')
        && $request['title'] === 'Enviar proposta'
        && $request['due'] === '2026-11-10T00:00:00.000Z');
});

it('lists pending tasks from the default task list', function (): void {
    $user = googleTasksUser();
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token-test', 'expires_in' => 3600]),
        'tasks.googleapis.com/tasks/v1/lists/@default/tasks*' => Http::response(['items' => [['id' => 'task-123', 'title' => 'Ligar para Ana']]]),
    ]);

    expect(app(GoogleTasksService::class)->listTasks($user))->toHaveCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && str_contains((string) parse_url($request->url(), PHP_URL_QUERY), 'showCompleted=false'));
});

it('collects completed and pending tasks across pages for a project summary', function (): void {
    $user = googleTasksUser();
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token-test', 'expires_in' => 3600]),
        'tasks.googleapis.com/tasks/v1/lists/project-1/tasks*' => Http::sequence()
            ->push(['items' => [['id' => 'task-open', 'title' => 'Enviar proposta', 'status' => 'needsAction']], 'nextPageToken' => 'page-2'])
            ->push(['items' => [['id' => 'task-done', 'title' => 'Revisar orçamento', 'status' => 'completed']]]),
    ]);

    $result = app(GoogleTasksService::class)->listTasksForSummary($user, 'project-1');

    expect($result['tasks'])->toHaveCount(2)
        ->and($result['truncated'])->toBeFalse();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && str_contains($request->url(), 'pageToken=page-2')
        && str_contains($request->url(), 'showCompleted=true')
        && str_contains($request->url(), 'showHidden=true'));
});

it('requires Google Tasks authorization before sending API requests', function (): void {
    $user = User::factory()->create();
    GoogleCalendarConnection::create([
        'user_id' => $user->id,
        'refresh_token' => Crypt::encryptString('calendar-only-refresh-token'),
        'scopes' => ['https://www.googleapis.com/auth/calendar.events.owned'],
        'calendar_id' => 'primary',
    ]);
    Http::fake();

    expect(fn () => app(GoogleTasksService::class)->listTasks($user))
        ->toThrow(RuntimeException::class, 'A permissão Google Tasks ainda não foi concedida.');
    Http::assertNothingSent();
});

it('patches a task title and date without replacing unrelated task fields', function (): void {
    $user = googleTasksUser();
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token-test', 'expires_in' => 3600]),
        'tasks.googleapis.com/tasks/v1/lists/@default/tasks/task-123' => Http::response(['id' => 'task-123', 'title' => 'Proposta revisada'], 200),
    ]);

    app(GoogleTasksService::class)->updateTask($user, 'task-123', [
        'title' => 'Proposta revisada',
        'due' => '2026-11-12',
    ]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
        && str_contains($request->url(), '/tasks/task-123')
        && $request['title'] === 'Proposta revisada'
        && $request['due'] === '2026-11-12T00:00:00.000Z');
});

it('marks a task completed through a patch request', function (): void {
    $user = googleTasksUser();
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token-test', 'expires_in' => 3600]),
        'tasks.googleapis.com/tasks/v1/lists/@default/tasks/task-123' => Http::response(['id' => 'task-123', 'status' => 'completed'], 200),
    ]);

    expect(app(GoogleTasksService::class)->completeTask($user, 'task-123')['status'])->toBe('completed');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
        && $request['status'] === 'completed');
});

it('deletes a task only through the selected Google Tasks list endpoint', function (): void {
    $user = googleTasksUser();
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token-test', 'expires_in' => 3600]),
        'tasks.googleapis.com/tasks/v1/lists/project-list-1/tasks/task-123' => Http::response(null, 204),
    ]);

    app(GoogleTasksService::class)->deleteTask($user, 'task-123', 'project-list-1');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/lists/project-list-1/tasks/task-123'));
});

it('creates a Google Task list for a project', function (): void {
    $user = googleTasksUser();
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token-test', 'expires_in' => 3600]),
        'tasks.googleapis.com/tasks/v1/users/@me/lists' => Http::response(['id' => 'project-list-1', 'title' => 'Lançamento'], 200),
    ]);

    expect(app(GoogleTasksService::class)->createProjectList($user, 'Lançamento')['id'])->toBe('project-list-1');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/users/@me/lists')
        && $request['title'] === 'Lançamento');
});

it('renames a project list with a patch request while preserving the list identifier', function (): void {
    $user = googleTasksUser();
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token-test', 'expires_in' => 3600]),
        'tasks.googleapis.com/tasks/v1/users/@me/lists/project-list-1' => Http::response(['id' => 'project-list-1', 'title' => 'Novo nome'], 200),
    ]);

    expect(app(GoogleTasksService::class)->renameProjectList($user, 'project-list-1', 'Novo nome')['title'])->toBe('Novo nome');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
        && str_ends_with($request->url(), '/users/@me/lists/project-list-1')
        && $request['title'] === 'Novo nome');
});

it('lists project task lists without the default list', function (): void {
    $user = googleTasksUser();
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token-test', 'expires_in' => 3600]),
        'tasks.googleapis.com/tasks/v1/users/@me/lists/@default' => Http::response(['id' => 'default-list-1', 'title' => 'Minhas tarefas']),
        'tasks.googleapis.com/tasks/v1/users/@me/lists*' => Http::response(['items' => [
            ['id' => 'default-list-1', 'title' => 'Minhas tarefas'],
            ['id' => 'project-list-1', 'title' => 'Lançamento'],
        ]]),
    ]);

    expect(app(GoogleTasksService::class)->listProjectLists($user))
        ->toBe([['id' => 'project-list-1', 'title' => 'Lançamento']]);
});

it('moves a task to another list using the official move endpoint', function (): void {
    $user = googleTasksUser();
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token-test', 'expires_in' => 3600]),
        'tasks.googleapis.com/tasks/v1/lists/@default/tasks/task-123/move*' => Http::response(['id' => 'task-123', 'title' => 'Enviar proposta'], 200),
    ]);

    expect(app(GoogleTasksService::class)->moveTask($user, 'task-123', '@default', 'project-list-1')['id'])
        ->toBe('task-123');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_contains($request->url(), '/tasks/task-123/move')
        && str_contains($request->url(), 'destinationTasklist=project-list-1'));
});
