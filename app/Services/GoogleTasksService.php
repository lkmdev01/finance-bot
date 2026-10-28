<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class GoogleTasksService
{
    private const TASK_LIST = '@default';

    public function __construct(private readonly GoogleCalendarOAuthService $oauth) {}

    public function createTask(User $user, array $task, string $taskListId = self::TASK_LIST): array
    {
        $validated = Validator::make($task, [
            'title' => ['required', 'string', 'max:1024'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:8192'],
            'due' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ])->validate();

        if (isset($validated['due'])) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $validated['due']);
            $errors = \DateTimeImmutable::getLastErrors();
            if (! $date || $date->format('Y-m-d') !== $validated['due']
                || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                throw new RuntimeException('A data da tarefa é inválida.');
            }
            $validated['due'] = $date->format('Y-m-d\TH:i:s.000\Z');
        }

        $response = $this->request($user)->post($this->taskListUrl($taskListId), $validated);
        $this->throwIfFailed($response->status(), $response->successful());

        return (array) $response->json();
    }

    public function listTasks(User $user, int $limit = 20, string $taskListId = self::TASK_LIST): array
    {
        $response = $this->request($user)->get($this->taskListUrl($taskListId), [
            'showCompleted' => 'false',
            'showHidden' => 'false',
            'maxResults' => min(max($limit, 1), 100),
        ]);
        $this->throwIfFailed($response->status(), $response->successful());

        return (array) $response->json('items', []);
    }

    /** @return array{tasks: array<int, array<string, mixed>>, truncated: bool} */
    public function listTasksForSummary(User $user, string $taskListId, int $limit = 1000): array
    {
        $limit = min(max($limit, 1), 1000);
        $tasks = [];
        $pageToken = null;
        $nextPageToken = null;

        do {
            $parameters = [
                'showCompleted' => 'true',
                'showHidden' => 'true',
                'maxResults' => min(100, $limit - count($tasks)),
            ];
            if ($pageToken !== null) {
                $parameters['pageToken'] = $pageToken;
            }

            $response = $this->request($user)->get($this->taskListUrl($taskListId), $parameters);
            $this->throwIfFailed($response->status(), $response->successful());
            $items = (array) $response->json('items', []);
            $tasks = array_merge($tasks, $items);
            $nextPageToken = $response->json('nextPageToken');

            if ($items === [] || ! is_string($nextPageToken) || $nextPageToken === '' || count($tasks) >= $limit) {
                break;
            }
            $pageToken = $nextPageToken;
        } while (count($tasks) < $limit);

        return [
            'tasks' => $tasks,
            'truncated' => is_string($nextPageToken) && $nextPageToken !== '',
        ];
    }

    public function updateTask(User $user, string $taskId, array $changes, string $taskListId = self::TASK_LIST): array
    {
        $validated = Validator::make($changes, [
            'title' => ['sometimes', 'string', 'min:1', 'max:1024'],
            'due' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ])->validate();

        if ($validated === []) {
            throw new RuntimeException('Nenhuma alteração de tarefa foi informada.');
        }
        if (isset($validated['due'])) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $validated['due']);
            $errors = \DateTimeImmutable::getLastErrors();
            if (! $date || $date->format('Y-m-d') !== $validated['due']
                || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                throw new RuntimeException('A data da tarefa é inválida.');
            }
            $validated['due'] = $date->format('Y-m-d\\TH:i:s.000\\Z');
        }

        $response = $this->request($user)->patch($this->taskUrl($taskListId, $taskId), $validated);
        $this->throwIfFailed($response->status(), $response->successful());

        return (array) $response->json();
    }

    public function completeTask(User $user, string $taskId, string $taskListId = self::TASK_LIST): array
    {
        $response = $this->request($user)->patch($this->taskUrl($taskListId, $taskId), ['status' => 'completed']);
        $this->throwIfFailed($response->status(), $response->successful());

        return (array) $response->json();
    }

    public function deleteTask(User $user, string $taskId, string $taskListId = self::TASK_LIST): void
    {
        $response = $this->request($user)->delete($this->taskUrl($taskListId, $taskId));
        if ($response->status() !== 404) {
            $this->throwIfFailed($response->status(), $response->successful());
        }
    }

    public function moveTask(User $user, string $taskId, string $sourceTaskListId, string $destinationTaskListId): array
    {
        if ($sourceTaskListId === $destinationTaskListId) {
            throw new RuntimeException('A tarefa já está nesse projeto.');
        }

        $url = $this->taskUrl($sourceTaskListId, $taskId).'/move?'.http_build_query([
            'destinationTasklist' => $destinationTaskListId,
        ]);
        $response = $this->request($user)->post($url, []);
        $this->throwIfFailed($response->status(), $response->successful());

        return (array) $response->json();
    }

    private function request(User $user)
    {
        if (! $user->googleCalendarConnection || $user->googleCalendarConnection->revoked_at) {
            throw new RuntimeException('A conta Google não está conectada.');
        }
        if (! in_array('https://www.googleapis.com/auth/tasks', (array) $user->googleCalendarConnection->scopes, true)) {
            throw new RuntimeException('A permissão Google Tasks ainda não foi concedida.');
        }

        return Http::acceptJson()->withToken($this->oauth->getAccessToken($user))->timeout(20);
    }

    public function createProjectList(User $user, string $title): array
    {
        $validated = Validator::make(['title' => $title], [
            'title' => ['required', 'string', 'min:1', 'max:1024'],
        ])->validate();

        $response = $this->request($user)->post('https://tasks.googleapis.com/tasks/v1/users/@me/lists', $validated);
        $this->throwIfFailed($response->status(), $response->successful());

        return (array) $response->json();
    }

    public function renameProjectList(User $user, string $taskListId, string $title): array
    {
        $validated = Validator::make(['title' => $title], [
            'title' => ['required', 'string', 'min:1', 'max:1024'],
        ])->validate();
        if ($taskListId === self::TASK_LIST) {
            throw new RuntimeException('A lista principal não pode ser renomeada como projeto.');
        }

        $response = $this->request($user)->patch(
            'https://tasks.googleapis.com/tasks/v1/users/@me/lists/'.rawurlencode($taskListId),
            $validated,
        );
        $this->throwIfFailed($response->status(), $response->successful());

        return (array) $response->json();
    }

    public function listProjectLists(User $user): array
    {
        $defaultResponse = $this->request($user)->get('https://tasks.googleapis.com/tasks/v1/users/@me/lists/@default');
        $this->throwIfFailed($defaultResponse->status(), $defaultResponse->successful());
        $defaultListId = (string) $defaultResponse->json('id', self::TASK_LIST);

        $response = $this->request($user)->get('https://tasks.googleapis.com/tasks/v1/users/@me/lists', [
            'maxResults' => 1000,
        ]);
        $this->throwIfFailed($response->status(), $response->successful());

        return array_values(array_filter((array) $response->json('items', []), static fn (array $list): bool => ! in_array(($list['id'] ?? null), [self::TASK_LIST, $defaultListId], true)));
    }

    private function taskListUrl(string $taskListId = self::TASK_LIST): string
    {
        $encodedListId = $taskListId === self::TASK_LIST ? self::TASK_LIST : rawurlencode($taskListId);

        return 'https://tasks.googleapis.com/tasks/v1/lists/'.$encodedListId.'/tasks';
    }

    private function taskUrl(string $taskListId, string $taskId): string
    {
        return $this->taskListUrl($taskListId).'/'.rawurlencode($taskId);
    }

    private function throwIfFailed(int $status, bool $successful): void
    {
        if (! $successful) {
            throw new RuntimeException('A solicitação ao Google Tasks falhou (HTTP '.$status.').');
        }
    }
}
