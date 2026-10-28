<?php

namespace App\Services\WhatsApp;

use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\GoogleTasksService;
use App\Services\TaskIntentParser;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class TaskCommandService
{
    private const PENDING_CREATE = 'google_task_create';
    private const PENDING_UPDATE = 'google_task_update';
    private const PENDING_COMPLETE = 'google_task_complete';
    private const PENDING_DELETE = 'google_task_delete';
    private const PENDING_MOVE = 'google_task_move';
    private const PENDING_PROJECT = 'google_project_create';
    private const PENDING_PROJECT_RENAME = 'google_project_rename';

    public function __construct(
        private readonly ConversationStateService $stateService,
        private readonly TaskIntentParser $parser,
        private readonly GoogleTasksService $tasks,
    ) {}

    public function handle(string $message, User $user, WhatsAppContact $contact): ?string
    {
        $state = $this->stateService->getState($contact);
        $pendingIntent = $state['pending_intent'] ?? null;
        $pending = in_array($pendingIntent, [self::PENDING_CREATE, self::PENDING_UPDATE, self::PENDING_COMPLETE, self::PENDING_DELETE, self::PENDING_MOVE, self::PENDING_PROJECT, self::PENDING_PROJECT_RENAME], true);
        $normalized = Str::of($message)->ascii()->lower()->squish()->toString();
        if (! $pending && ! $this->looksLikeTaskRequest($normalized)) {
            return null;
        }

        // Cancellation must remain available even if Google authorization was revoked.
        if ($pending && in_array($normalized, ['nao', 'nao quero', 'cancela', 'cancelar'], true)) {
            $this->stateService->clearPending($contact);

            return match ($pendingIntent) {
                self::PENDING_CREATE, self::PENDING_PROJECT => 'Tudo bem, não criei essa tarefa ou projeto.',
                self::PENDING_PROJECT_RENAME => 'Tudo bem, não renomeei o projeto.',
                self::PENDING_MOVE => 'Tudo bem, não movi a tarefa.',
                default => 'Tudo bem, não alterei a tarefa.',
            };
        }

        if (! $pending && ! empty($state['pending_intent'])) {
            if (str_starts_with((string) $state['pending_intent'], 'google_task_')) {
                $this->stateService->clearPending($contact);
            } else {
                return 'Você tem outra confirmação em andamento. Conclua ou cancele essa ação antes de iniciar uma tarefa.';
            }
        }

        if (! $user->googleCalendarConnection || $user->googleCalendarConnection->revoked_at) {
            if ($pending) {
                $this->stateService->clearPending($contact);
            }

            return 'Para usar tarefas, conecte sua conta Google em: '.route('integrations.google-calendar');
        }
        if (! in_array('https://www.googleapis.com/auth/tasks', (array) $user->googleCalendarConnection->scopes, true)) {
            return 'O acesso ao Google Tasks ainda não foi autorizado. Atualize as permissões da conta em: '.route('integrations.google-calendar');
        }

        if ($pending && ($state['mode'] ?? '') === 'awaiting_confirmation') {
            return $this->confirmPending($normalized, $user, $contact, (string) $pendingIntent);
        }

        $original = $pending
            ? trim((string) data_get($state, 'pending_payload.original_message').' '.$message)
            : $message;
        try {
            $intent = $this->parser->parse($original, CarbonImmutable::now(), (string) config('app.timezone', 'America/Sao_Paulo'));
        } catch (Throwable $exception) {
            Log::warning('Falha ao interpretar pedido de tarefa', [
                'user_id' => $user->id,
                'exception' => $exception::class,
            ]);

            return 'Não consegui entender a tarefa agora. Tente dizer o que precisa ser feito e, se houver, o prazo.';
        }

        return match ($intent['intent'] ?? 'other') {
            'create_task' => $this->prepareCreate($intent, $original, $user, $contact),
            'list_tasks' => $this->listTasks($user, (string) ($intent['project_title'] ?? '')),
            'update_task' => $this->prepareUpdate($intent, $user, $contact),
            'complete_task' => $this->prepareComplete($intent, $user, $contact),
            'delete_task' => $this->prepareDelete($intent, $user, $contact),
            'move_task' => $this->prepareMove($intent, $user, $contact),
            'create_project' => $this->prepareProject($intent, $user, $contact),
            'list_projects' => $this->listProjects($user),
            'rename_project' => $this->prepareRenameProject($intent, $user, $contact),
            'summarize_project' => $this->summarizeProject($intent, $user),
            default => 'Posso criar projetos e tarefas, listar tarefas pendentes, atualizar, concluir ou mover tarefas entre projetos. Para compromissos com horário, peça para consultar a agenda.',
        };
    }

    private function looksLikeTaskRequest(string $message): bool
    {
        return preg_match('/\b(tarefa|tarefas|pendencia|pendencias|task|todo list)\b/u', $message) === 1
            || preg_match('/\b(projeto|projetos)\b/u', $message) === 1
            || (preg_match('/\b(concluir|finalizar|terminei|feito|feita|atualizar|alterar|mudar|adiar)\b/u', $message) === 1
                && preg_match('/\b(tarefa|tarefas|pendencia|pendencias|task)\b/u', $message) === 1)
            || preg_match('/\b(anota|registre|registrar|criar|crie|adicionar|adicione)\b.{0,50}\b(fazer|ligar|enviar|pagar|comprar|entregar|revisar|concluir)\b/u', $message) === 1;
    }

    private function prepareCreate(array $intent, string $original, User $user, WhatsAppContact $contact): string
    {
        $title = trim((string) ($intent['title'] ?? ''));
        if ($title === '') {
            $this->stateService->markAwaitingClarification($contact, self::PENDING_CREATE, ['original_message' => $original]);

            return 'O que exatamente você quer registrar como tarefa?';
        }

        $task = [
            'title' => mb_substr($title, 0, 1024),
            'notes' => isset($intent['notes']) ? mb_substr((string) $intent['notes'], 0, 8192) : null,
            'due_date' => $intent['due_date'] ?? null,
        ];
        if ($task['due_date'] !== null && (! is_string($task['due_date']) || ! $this->isValidDate($task['due_date']))) {
            $this->stateService->markAwaitingClarification($contact, self::PENDING_CREATE, ['original_message' => $original]);

            return 'Não consegui validar o prazo. Informe a data completa no formato dia/mês/ano, ou diga que a tarefa não tem prazo.';
        }

        $taskListId = null;
        $projectTitle = trim((string) ($intent['project_title'] ?? ''));
        if ($projectTitle !== '') {
            $project = $this->findProjectList($user, $projectTitle);
            if ($project === null) {
                return 'Não consegui consultar seus projetos agora. Tente novamente em alguns instantes.';
            }
            if (count($project) !== 1) {
                return $this->describeProjectMatchResult($project);
            }
            $taskListId = (string) $project[0]['id'];
        }

        $key = hash('sha256', $contact->id.'|'.now()->toIso8601String().'|'.json_encode([$task, $taskListId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->stateService->markAwaitingConfirmation($contact, self::PENDING_CREATE, [
            'task' => $task,
            'task_list_id' => $taskListId,
            'project_title' => $projectTitle ?: null,
            'idempotency_key' => $key,
            'submission_status' => 'pending',
        ]);

        $due = $task['due_date'] ? "\nPrazo: ".CarbonImmutable::parse($task['due_date'])->format('d/m/Y') : '';

        $inProject = $projectTitle !== '' ? "\nProjeto: {$projectTitle}" : '';

        return "Confirma registrar esta tarefa no Google Tasks?\n{$task['title']}{$due}{$inProject}\nResponda *sim* para confirmar ou *não* para cancelar.";
    }

    private function prepareUpdate(array $intent, User $user, WhatsAppContact $contact): string
    {
        [$taskListId, $taskListTitle, $projectProblem] = $this->resolveProjectContext($user, (string) ($intent['project_title'] ?? ''));
        if ($projectProblem !== null) {
            return $projectProblem;
        }
        $matches = $this->findTaskMatches($user, (string) ($intent['target_query'] ?? ''), $taskListId, $taskListTitle);
        if ($matches === null) {
            return 'Não consegui consultar suas tarefas agora. Tente novamente em alguns instantes.';
        }
        if (count($matches) !== 1) {
            return $this->describeTaskMatchResult($matches);
        }

        $task = $matches[0];
        $changes = [];
        $newTitle = mb_substr(trim((string) ($intent['new_title'] ?? '')), 0, 1024);
        if ($newTitle !== '') {
            $changes['title'] = mb_substr($newTitle, 0, 1024);
        }
        $dueDate = $intent['due_date'] ?? null;
        if ($dueDate !== null) {
            if (! is_string($dueDate) || ! $this->isValidDate($dueDate)) {
                return 'Não consegui validar o novo prazo. Informe a data completa no formato dia/mês/ano.';
            }
            $changes['due'] = $dueDate;
        }
        if ($changes === []) {
            return 'Diga qual deve ser o novo título ou prazo da tarefa.';
        }

        $this->stateService->markAwaitingConfirmation($contact, self::PENDING_UPDATE, [
            'task_id' => (string) ($task['id'] ?? ''),
            'task_list_id' => (string) ($task['_task_list_id'] ?? '@default'),
            'project_title' => $task['_task_list_title'] ?? null,
            'task_title' => (string) ($task['title'] ?? 'Sem título'),
            'changes' => $changes,
            'submission_status' => 'pending',
        ]);
        $summary = [];
        if (isset($changes['title'])) {
            $summary[] = 'novo título: '.$changes['title'];
        }
        if (isset($changes['due'])) {
            $summary[] = 'novo prazo: '.CarbonImmutable::parse($changes['due'])->format('d/m/Y');
        }

        return 'Confirma atualizar a tarefa "'.($task['title'] ?? 'Sem título').'" ('.implode('; ', $summary).')? Responda *sim* ou *não*.';
    }

    private function prepareComplete(array $intent, User $user, WhatsAppContact $contact): string
    {
        [$taskListId, $taskListTitle, $projectProblem] = $this->resolveProjectContext($user, (string) ($intent['project_title'] ?? ''));
        if ($projectProblem !== null) {
            return $projectProblem;
        }
        $matches = $this->findTaskMatches($user, (string) ($intent['target_query'] ?? ''), $taskListId, $taskListTitle);
        if ($matches === null) {
            return 'Não consegui consultar suas tarefas agora. Tente novamente em alguns instantes.';
        }
        if (count($matches) !== 1) {
            return $this->describeTaskMatchResult($matches);
        }

        $task = $matches[0];
        $this->stateService->markAwaitingConfirmation($contact, self::PENDING_COMPLETE, [
            'task_id' => (string) ($task['id'] ?? ''),
            'task_list_id' => (string) ($task['_task_list_id'] ?? '@default'),
            'project_title' => $task['_task_list_title'] ?? null,
            'task_title' => (string) ($task['title'] ?? 'Sem título'),
            'submission_status' => 'pending',
        ]);

        return 'Confirma marcar como concluída a tarefa "'.($task['title'] ?? 'Sem título').'"? Responda *sim* ou *não*.';
    }

    private function prepareDelete(array $intent, User $user, WhatsAppContact $contact): string
    {
        [$taskListId, $taskListTitle, $projectProblem] = $this->resolveProjectContext($user, (string) ($intent['project_title'] ?? ''));
        if ($projectProblem !== null) {
            return $projectProblem;
        }
        $matches = $this->findTaskMatches($user, (string) ($intent['target_query'] ?? ''), $taskListId, $taskListTitle);
        if ($matches === null) {
            return 'Não consegui consultar suas tarefas agora. Tente novamente em alguns instantes.';
        }
        if (count($matches) !== 1) {
            return $this->describeTaskMatchResult($matches);
        }

        $task = $matches[0];
        $this->stateService->markAwaitingConfirmation($contact, self::PENDING_DELETE, [
            'task_id' => (string) ($task['id'] ?? ''),
            'task_list_id' => (string) ($task['_task_list_id'] ?? '@default'),
            'project_title' => $task['_task_list_title'] ?? null,
            'task_title' => (string) ($task['title'] ?? 'Sem título'),
            'submission_status' => 'pending',
        ]);

        $location = $taskListTitle ? ' do projeto "'.$taskListTitle.'"' : ' da lista principal';

        return 'Confirma excluir permanentemente a tarefa "'.($task['title'] ?? 'Sem título').'"'.$location.'? Responda *sim* para excluir ou *não* para cancelar.';
    }

    private function prepareMove(array $intent, User $user, WhatsAppContact $contact): string
    {
        [$sourceId, $sourceTitle, $sourceProblem] = $this->resolveProjectContext($user, (string) ($intent['source_project_title'] ?? ''));
        if ($sourceProblem !== null) {
            return $sourceProblem;
        }
        [$destinationId, $destinationTitle, $destinationProblem] = $this->resolveProjectContext($user, (string) ($intent['project_title'] ?? ''));
        if ($destinationProblem !== null) {
            return $destinationProblem;
        }
        if ($destinationTitle === null) {
            return 'Informe o nome do projeto para onde quer mover a tarefa.';
        }
        if ($sourceId === $destinationId) {
            return 'A tarefa já está nesse projeto.';
        }

        $matches = $this->findTaskMatches($user, (string) ($intent['target_query'] ?? ''), $sourceId, $sourceTitle);
        if ($matches === null) {
            return 'Não consegui consultar suas tarefas agora. Tente novamente em alguns instantes.';
        }
        if (count($matches) !== 1) {
            return $this->describeTaskMatchResult($matches);
        }

        $task = $matches[0];
        $this->stateService->markAwaitingConfirmation($contact, self::PENDING_MOVE, [
            'task_id' => (string) $task['id'],
            'task_title' => (string) ($task['title'] ?? 'Sem título'),
            'source_task_list_id' => $sourceId,
            'source_project_title' => $sourceTitle,
            'destination_task_list_id' => $destinationId,
            'destination_project_title' => $destinationTitle,
            'submission_status' => 'pending',
        ]);

        $origin = $sourceTitle ? ' de "'.$sourceTitle.'"' : ' da lista principal';

        return 'Confirma mover "'.($task['title'] ?? 'Sem título').$origin.' para o projeto "'.$destinationTitle.'"? Responda *sim* ou *não*.';
    }

    private function findTaskMatches(User $user, string $query, string $taskListId = '@default', ?string $taskListTitle = null): ?array
    {
        $query = $this->normalizeTitle($query);
        if ($query === '') {
            return [];
        }

        try {
            $tasks = $this->tasks->listTasks($user, 100, $taskListId);
        } catch (Throwable $exception) {
            Log::warning('Falha ao localizar tarefa no Google Tasks', [
                'user_id' => $user->id,
                'exception' => $exception::class,
            ]);

            return null;
        }

        return array_values(array_map(function (array $task) use ($query, $taskListId, $taskListTitle): array {
            $task['_task_list_id'] = $taskListId;
            $task['_task_list_title'] = $taskListTitle;

            return $task;
        }, array_filter($tasks, function (array $task) use ($query): bool {
            $title = $this->normalizeTitle((string) ($task['title'] ?? ''));

            return ! empty($task['id']) && $title !== '' && (str_contains($title, $query) || str_contains($query, $title));
        })));
    }

    private function normalizeTitle(string $title): string
    {
        return Str::of($title)->ascii()->lower()->squish()->toString();
    }

    private function describeTaskMatchResult(array $matches): string
    {
        if ($matches === []) {
            return 'Não encontrei essa tarefa pendente. Confira o título ou peça para listar suas tarefas.';
        }

        $options = collect($matches)->take(5)->map(fn (array $task): string => '• '.($task['title'] ?? 'Sem título'))->implode("\n");

        return "Encontrei mais de uma tarefa parecida. Diga o título completo de uma delas:\n{$options}";
    }

    private function prepareProject(array $intent, User $user, WhatsAppContact $contact): string
    {
        $title = trim((string) ($intent['project_title'] ?? ''));
        if ($title === '') {
            return 'Qual nome você quer dar ao projeto?';
        }

        $existing = $this->findProjectList($user, $title);
        if ($existing === null) {
            return 'Não consegui consultar seus projetos agora. Tente novamente em alguns instantes.';
        }
        if ($existing !== []) {
            return 'Já existe um projeto com esse nome no Google Tasks.';
        }

        $this->stateService->markAwaitingConfirmation($contact, self::PENDING_PROJECT, [
            'project_title' => mb_substr($title, 0, 1024),
            'submission_status' => 'pending',
        ]);

        return 'Vou criar "'.$title.'" como uma lista de tarefas no Google Tasks. Confirma? Responda *sim* ou *não*.';
    }

    private function prepareRenameProject(array $intent, User $user, WhatsAppContact $contact): string
    {
        $currentTitle = trim((string) ($intent['project_title'] ?? ''));
        $newTitle = trim((string) ($intent['new_title'] ?? ''));
        if ($currentTitle === '' || $newTitle === '') {
            return 'Informe o nome atual do projeto e o novo nome desejado.';
        }

        $matches = $this->findProjectList($user, $currentTitle);
        if ($matches === null) {
            return 'Não consegui consultar seus projetos agora. Tente novamente em alguns instantes.';
        }
        if (count($matches) !== 1) {
            return $this->describeProjectMatchResult($matches);
        }
        if ($this->normalizeTitle($currentTitle) === $this->normalizeTitle($newTitle)) {
            return 'O projeto já tem esse nome.';
        }

        $duplicates = $this->findProjectList($user, $newTitle);
        if ($duplicates === null) {
            return 'Não consegui verificar se o novo nome está disponível. Tente novamente em alguns instantes.';
        }
        if ($duplicates !== []) {
            return 'Já existe um projeto com o nome "'.$newTitle.'" no Google Tasks.';
        }

        $this->stateService->markAwaitingConfirmation($contact, self::PENDING_PROJECT_RENAME, [
            'task_list_id' => (string) $matches[0]['id'],
            'current_title' => (string) $matches[0]['title'],
            'new_title' => mb_substr($newTitle, 0, 1024),
            'submission_status' => 'pending',
        ]);

        return 'Confirma renomear o projeto "'.$matches[0]['title'].'" para "'.$newTitle.'"? As tarefas continuarão nessa lista. Responda *sim* ou *não*.';
    }

    private function listProjects(User $user): string
    {
        try {
            $projects = $this->tasks->listProjectLists($user);
        } catch (Throwable $exception) {
            Log::warning('Falha ao consultar projetos do Google Tasks', [
                'user_id' => $user->id,
                'exception' => $exception::class,
            ]);

            return 'Não consegui consultar seus projetos agora. Tente novamente em alguns instantes.';
        }

        if ($projects === []) {
            return 'Você ainda não tem projetos. Posso criar um como uma lista do Google Tasks.';
        }

        return "Seus projetos (listas do Google Tasks):\n".collect($projects)->take(20)
            ->map(static fn (array $project): string => '• '.($project['title'] ?? 'Sem título'))
            ->implode("\n");
    }

    private function summarizeProject(array $intent, User $user): string
    {
        $projectTitle = trim((string) ($intent['project_title'] ?? ''));
        if ($projectTitle === '') {
            return 'Qual projeto você quer resumir? Diga o nome do projeto.';
        }

        $projects = $this->findProjectList($user, $projectTitle);
        if ($projects === null) {
            return 'Não consegui consultar seus projetos agora. Tente novamente em alguns instantes.';
        }
        if (count($projects) !== 1) {
            return $this->describeProjectMatchResult($projects);
        }

        try {
            $result = $this->tasks->listTasksForSummary($user, (string) $projects[0]['id']);
        } catch (Throwable $exception) {
            Log::warning('Falha ao gerar resumo do projeto no Google Tasks', [
                'user_id' => $user->id,
                'exception' => $exception::class,
            ]);

            return 'Não consegui consultar as tarefas desse projeto agora. Tente novamente em alguns instantes.';
        }

        $items = $result['tasks'];
        $timezone = (string) config('app.timezone', 'America/Sao_Paulo');
        $today = CarbonImmutable::now($timezone)->toDateString();
        $completed = array_values(array_filter($items, static fn (array $task): bool => ($task['status'] ?? 'needsAction') === 'completed'));
        $pending = array_values(array_filter($items, static fn (array $task): bool => ($task['status'] ?? 'needsAction') !== 'completed'));
        $overdue = array_values(array_filter($pending, static function (array $task) use ($today): bool {
            $due = substr((string) ($task['due'] ?? ''), 0, 10);

            return preg_match('/^\d{4}-\d{2}-\d{2}$/D', $due) === 1 && $due < $today;
        }));
        usort($overdue, static fn (array $left, array $right): int => strcmp((string) ($left['due'] ?? ''), (string) ($right['due'] ?? '')));
        $upcoming = array_values(array_filter($pending, static function (array $task) use ($today): bool {
            $due = substr((string) ($task['due'] ?? ''), 0, 10);

            return preg_match('/^\d{4}-\d{2}-\d{2}$/D', $due) === 1 && $due >= $today;
        }));
        usort($upcoming, static fn (array $left, array $right): int => strcmp((string) ($left['due'] ?? ''), (string) ($right['due'] ?? '')));

        $lines = [
            'Resumo do projeto "'.$projects[0]['title'].'":',
            'Pendentes: '.count($pending),
            'Concluídas: '.count($completed),
            'Atrasadas: '.count($overdue),
        ];
        if ($overdue !== []) {
            $lines[] = 'Prazos atrasados:';
            foreach (array_slice($overdue, 0, 5) as $task) {
                $lines[] = '• '.CarbonImmutable::parse(substr((string) $task['due'], 0, 10), $timezone)->format('d/m/Y').' — '.($task['title'] ?? 'Sem título');
            }
        }
        if ($upcoming !== []) {
            $lines[] = 'Próximos prazos:';
            foreach (array_slice($upcoming, 0, 5) as $task) {
                $lines[] = '• '.CarbonImmutable::parse(substr((string) $task['due'], 0, 10), $timezone)->format('d/m/Y').' — '.($task['title'] ?? 'Sem título');
            }
        }
        if ($result['truncated']) {
            $lines[] = 'Observação: consultei até 1.000 tarefas; a lista pode conter mais itens.';
        }

        return implode("\n", $lines);
    }

    /** @return array{0:string,1:?string,2:?string} */
    private function resolveProjectContext(User $user, string $projectTitle): array
    {
        if (trim($projectTitle) === '') {
            return ['@default', null, null];
        }

        $projects = $this->findProjectList($user, $projectTitle);
        if ($projects === null) {
            return ['@default', null, 'Não consegui consultar seus projetos agora. Tente novamente em alguns instantes.'];
        }
        if (count($projects) !== 1) {
            return ['@default', null, $this->describeProjectMatchResult($projects)];
        }

        return [(string) $projects[0]['id'], (string) $projects[0]['title'], null];
    }

    private function findProjectList(User $user, string $title): ?array
    {
        try {
            $projects = $this->tasks->listProjectLists($user);
        } catch (Throwable $exception) {
            Log::warning('Falha ao localizar lista de projeto no Google Tasks', [
                'user_id' => $user->id,
                'exception' => $exception::class,
            ]);

            return null;
        }

        $normalized = $this->normalizeTitle($title);

        return array_values(array_filter($projects, fn (array $project): bool => $this->normalizeTitle((string) ($project['title'] ?? '')) === $normalized));
    }

    private function findProjectListById(User $user, string $taskListId): ?array
    {
        try {
            $projects = $this->tasks->listProjectLists($user);
        } catch (Throwable $exception) {
            Log::warning('Falha ao verificar projeto antes de renomear no Google Tasks', [
                'user_id' => $user->id,
                'exception' => $exception::class,
            ]);

            return null;
        }

        return array_values(array_filter($projects, static fn (array $project): bool => (string) ($project['id'] ?? '') === $taskListId));
    }

    private function describeProjectMatchResult(array $projects): string
    {
        if ($projects === []) {
            return 'Não encontrei esse projeto. Peça para listar seus projetos ou informe o nome exato.';
        }

        return 'Encontrei mais de um projeto com esse nome. Informe um nome diferente para identificar a lista.';
    }

    private function confirmPending(string $normalized, User $user, WhatsAppContact $contact, string $pendingIntent): string
    {
        if (in_array($normalized, ['nao', 'nao quero', 'cancela', 'cancelar'], true)) {
            $this->stateService->clearPending($contact);

            return match ($pendingIntent) {
                self::PENDING_CREATE, self::PENDING_PROJECT => 'Tudo bem, não criei essa tarefa ou projeto.',
                self::PENDING_PROJECT_RENAME => 'Tudo bem, não renomeei o projeto.',
                self::PENDING_MOVE => 'Tudo bem, não movi a tarefa.',
                default => 'Tudo bem, não alterei a tarefa.',
            };
        }
        if (! in_array($normalized, ['sim', 'confirmo', 'confirmar', 'pode criar'], true)) {
            return 'A alteração ainda não foi aplicada. Responda *sim* para confirmar ou *não* para cancelar.';
        }

        try {
            $pendingPayload = (array) data_get($this->stateService->getState($contact), 'pending_payload', []);
            $lockValue = $pendingIntent === self::PENDING_PROJECT_RENAME
                ? 'rename:'.(string) ($pendingPayload['task_list_id'] ?? '')
                : 'create:'.$this->normalizeTitle((string) ($pendingPayload['project_title'] ?? ''));
            $lockKey = in_array($pendingIntent, [self::PENDING_PROJECT, self::PENDING_PROJECT_RENAME], true)
                ? 'google-project-operation-user-'.$user->id.'-'.hash('sha256', $lockValue)
                : 'google-task-mutation-contact-'.$contact->id;

            return Cache::lock($lockKey, 45)->block(5, function () use ($user, $contact, $pendingIntent): string {
                $contact->refresh();
                $state = $this->stateService->getState($contact);
                if (($state['pending_intent'] ?? null) !== $pendingIntent) {
                    return 'Não encontrei uma confirmação de tarefa pendente.';
                }
                $current = (array) ($state['pending_payload'] ?? []);
                if (($current['submission_status'] ?? null) !== 'pending') {
                    return 'Não vou reenviar esta tarefa automaticamente porque o resultado anterior pode ter sido recebido pelo Google. Confira sua lista de tarefas antes de pedir novamente.';
                }

                if ($pendingIntent === self::PENDING_PROJECT) {
                    $existingProjects = $this->findProjectList($user, (string) ($current['project_title'] ?? ''));
                    if ($existingProjects === null) {
                        return 'Não consegui verificar se o projeto já existe. Tente confirmar novamente em alguns instantes.';
                    }
                    if ($existingProjects !== []) {
                        $this->stateService->clearPending($contact);

                        return 'Já existe um projeto com esse nome no Google Tasks.';
                    }
                }

                if ($pendingIntent === self::PENDING_PROJECT_RENAME) {
                    $projects = $this->findProjectListById($user, (string) ($current['task_list_id'] ?? ''));
                    if ($projects === null) {
                        return 'Não consegui verificar o projeto antes de renomeá-lo. Tente confirmar novamente em alguns instantes.';
                    }
                    if ($projects === [] || ($projects[0]['title'] ?? null) !== ($current['current_title'] ?? null)) {
                        $this->stateService->clearPending($contact);

                        return 'O projeto mudou desde a consulta. Não o renomeei; liste os projetos e tente novamente.';
                    }
                    $newNameMatches = $this->findProjectList($user, (string) ($current['new_title'] ?? ''));
                    if ($newNameMatches === null) {
                        return 'Não consegui verificar se o novo nome continua disponível. Tente confirmar novamente em alguns instantes.';
                    }
                    if ($newNameMatches !== []) {
                        $this->stateService->clearPending($contact);

                        return 'Já existe um projeto com esse nome. Não fiz a alteração.';
                    }
                }

                $current['submission_status'] = 'submitting';
                $contact->forceFill(['conversation_state' => array_merge($state, ['pending_payload' => $current])])->save();

                try {
                    $task = match ($pendingIntent) {
                        self::PENDING_CREATE => $this->tasks->createTask($user, array_filter([
                            'title' => $current['task']['title'] ?? null,
                            'notes' => $current['task']['notes'] ?? null,
                            'due' => $current['task']['due_date'] ?? null,
                        ], static fn ($value): bool => $value !== null), (string) ($current['task_list_id'] ?? '@default')),
                        self::PENDING_UPDATE => $this->tasks->updateTask($user, (string) $current['task_id'], (array) $current['changes'], (string) ($current['task_list_id'] ?? '@default')),
                        self::PENDING_COMPLETE => $this->tasks->completeTask($user, (string) $current['task_id'], (string) ($current['task_list_id'] ?? '@default')),
                        self::PENDING_DELETE => $this->tasks->deleteTask($user, (string) $current['task_id'], (string) ($current['task_list_id'] ?? '@default')),
                        self::PENDING_MOVE => $this->tasks->moveTask($user, (string) $current['task_id'], (string) $current['source_task_list_id'], (string) $current['destination_task_list_id']),
                        self::PENDING_PROJECT => $this->tasks->createProjectList($user, (string) $current['project_title']),
                        self::PENDING_PROJECT_RENAME => $this->tasks->renameProjectList($user, (string) $current['task_list_id'], (string) $current['new_title']),
                        default => throw new \LogicException('Intenção de tarefa pendente inválida.'),
                    };
                } catch (Throwable $exception) {
                    $current['submission_status'] = 'unknown';
                    $contact->forceFill(['conversation_state' => array_merge($state, ['pending_payload' => $current])])->save();
                    Log::warning('Resultado incerto ao alterar tarefa no Google Tasks', [
                        'user_id' => $user->id,
                        'contact_id' => $contact->id,
                        'exception' => $exception::class,
                    ]);

                    return 'Não consegui confirmar o resultado no Google Tasks. Para evitar alterações duplicadas, não vou reenviar automaticamente. Confira sua lista antes de tentar novamente.';
                }

                $this->stateService->clearPending($contact);

                return match ($pendingIntent) {
                    self::PENDING_CREATE => 'Tarefa registrada no Google Tasks: '.($task['title'] ?? $current['task']['title'] ?? 'tarefa'),
                    self::PENDING_UPDATE => 'Tarefa atualizada no Google Tasks: '.($task['title'] ?? $current['task_title'] ?? 'tarefa'),
                    self::PENDING_COMPLETE => 'Tarefa marcada como concluída no Google Tasks: '.($task['title'] ?? $current['task_title'] ?? 'tarefa'),
                    self::PENDING_DELETE => 'Tarefa excluída do Google Tasks: '.($current['task_title'] ?? 'tarefa'),
                    self::PENDING_MOVE => 'Tarefa movida para o projeto "'.$current['destination_project_title'].'": '.($task['title'] ?? $current['task_title'] ?? 'tarefa'),
                    self::PENDING_PROJECT => 'Projeto criado como lista no Google Tasks: '.($task['title'] ?? $current['project_title']),
                    self::PENDING_PROJECT_RENAME => 'Projeto renomeado no Google Tasks: '.($task['title'] ?? $current['new_title']),
                    default => 'Tarefa atualizada.',
                };
            });
        } catch (LockTimeoutException) {
            return 'A confirmação da tarefa ainda está sendo processada. Aguarde alguns instantes e consulte suas tarefas.';
        }
    }

    private function listTasks(User $user, string $projectTitle = ''): string
    {
        try {
            [$taskListId, $taskListTitle, $projectProblem] = $this->resolveProjectContext($user, $projectTitle);
            if ($projectProblem !== null) {
                return $projectProblem;
            }
            $tasks = $this->tasks->listTasks($user, 20, $taskListId);
        } catch (Throwable $exception) {
            Log::warning('Falha ao consultar tarefas do Google Tasks', [
                'user_id' => $user->id,
                'exception' => $exception::class,
            ]);

            return 'Não consegui consultar suas tarefas agora. Tente novamente em alguns instantes.';
        }

        if ($tasks === []) {
            return $taskListTitle
                ? 'O projeto "'.$taskListTitle.'" não tem tarefas pendentes.'
                : 'Você não tem tarefas pendentes na lista principal do Google Tasks.';
        }

        $heading = $taskListTitle ? 'Tarefas pendentes em "'.$taskListTitle.'":' : 'Suas tarefas pendentes:';

        return $heading."\n".collect($tasks)->take(12)->map(function (array $task): string {
            $due = isset($task['due']) ? ' — prazo '.CarbonImmutable::parse($task['due'])->format('d/m/Y') : '';

            return '• '.($task['title'] ?? 'Sem título').$due;
        })->implode("\n");
    }

    private function isValidDate(string $date): bool
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) {
            return false;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = \DateTimeImmutable::getLastErrors();

        return $parsed !== false && $parsed->format('Y-m-d') === $date
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
    }
}
