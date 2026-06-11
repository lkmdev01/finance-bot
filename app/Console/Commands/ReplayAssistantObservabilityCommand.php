<?php

namespace App\Console\Commands;

use App\Assistant\Reports\AssistantObservabilityService;
use App\Models\User;
use App\Services\PhoneNumberService;
use App\Services\WhatsApp\ConversationSimulationService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

class ReplayAssistantObservabilityCommand extends Command
{
    protected $signature = 'assistant:replay-observability
        {user? : ID, email ou telefone do usuario para replay}
        {--days=14 : Janela em dias}
        {--sample=1000 : Tamanho da amostra}
        {--focus=all : all|unknown|missing}
        {--domain= : Filtra um dominio especifico}
        {--item-key= : Filtra um item especifico do backlog}
        {--limit=5 : Quantidade maxima de entradas}
        {--phone= : Numero a simular (padrao: telefone do usuario)}
        {--push-name=Observability Replay : Nome exibido no remetente}
        {--remote-jid= : JID remoto customizado}
        {--reset-state : Limpa o estado da conversa antes de iniciar}
        {--json : Imprime o transcript final em JSON}
        {--output= : Salva o transcript final em arquivo .json}
        {--assert : Falha se alguma expectativa do replay nao for atendida}';

    protected $description = 'Reexecuta exemplos reais da observabilidade do assistente para validar regressao localmente.';

    public function handle(
        AssistantObservabilityService $observabilityService,
        ConversationSimulationService $simulationService,
        PhoneNumberService $phoneNumberService,
    ): int {
        $days = max(1, min(30, (int) $this->option('days')));
        $sample = max(10, min(5000, (int) $this->option('sample')));
        $limit = max(1, min(100, (int) $this->option('limit')));
        $focus = (string) $this->option('focus');
        $domain = $this->nullableOption('domain');
        $itemKey = $this->nullableOption('item-key');

        $template = $observabilityService->replayTranscript(
            days: $days,
            sampleSize: $sample,
            focus: $focus,
            domain: $domain,
            itemKey: $itemKey,
            limit: $limit,
        );

        $entries = $template['entries'] ?? [];
        if ($entries === []) {
            $this->warn('Nenhum replay elegivel foi encontrado para os filtros informados.');

            return self::SUCCESS;
        }

        $this->line('Replays encontrados: '.count($entries));

        $user = $this->resolveExplicitUser((string) ($this->argument('user') ?? ''), $phoneNumberService);
        if (! $user && $this->option('phone')) {
            $user = $this->resolveExplicitUser((string) $this->option('phone'), $phoneNumberService);
        }

        if (! $user) {
            $user = $this->resolveSingleSourceUser(collect($entries));
        }

        if (! $user) {
            $this->warn('Nao consegui inferir um unico usuario para executar o replay.');
            $this->persistOutputIfNeeded($template);
            $this->renderTemplatePreview($template);

            return self::SUCCESS;
        }

        $phoneNumber = $this->resolvePhoneNumber($user, $phoneNumberService);
        if ($phoneNumber === null) {
            $this->error('Nao consegui determinar o telefone para o replay. Use --phone=5511...');

            return self::FAILURE;
        }

        $contact = $simulationService->prepareContact(
            $user,
            $phoneNumber,
            (string) $this->option('push-name'),
            (bool) $this->option('reset-state'),
        );

        $transcript = $simulationService->simulate($user, $contact, $phoneNumber, $entries, [
            'push_name' => (string) $this->option('push-name'),
            'remote_jid' => (string) ($this->option('remote-jid') ?? ''),
        ]);

        $transcript['source'] = 'assistant_observability';
        $transcript['filters'] = $template['filters'] ?? [];
        $transcript['replay_entries'] = count($entries);

        $this->persistOutputIfNeeded($transcript);
        $this->renderSimulationTranscript($transcript);

        if ($this->option('assert') && ($transcript['all_passed'] ?? false) !== true) {
            $this->error('Replay executado, mas houve falhas nas expectativas.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $transcript
     */
    private function renderTemplatePreview(array $transcript): void
    {
        if ($this->option('json')) {
            $this->line(json_encode($transcript, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return;
        }

        foreach ($transcript['entries'] ?? [] as $index => $entry) {
            $this->line(sprintf(
                '%d. [%s] %s',
                $index + 1,
                $entry['label'] ?? ($entry['domain'] ?? 'replay'),
                $entry['message'] ?? ''
            ));
        }
    }

    /**
     * @param array<string, mixed> $transcript
     */
    private function renderSimulationTranscript(array $transcript): void
    {
        if ($this->option('json')) {
            $this->line(json_encode($transcript, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return;
        }

        foreach ($transcript['rounds'] ?? [] as $round) {
            $this->info('Mensagem '.($round['index'] ?? '?'));
            if (! empty($round['label'])) {
                $this->line('Label: '.$round['label']);
            }

            $this->line('Usuario: '.($round['input']['message'] ?? ''));
            $this->line('Bot: '.($round['reply'] ?? ''));
            $this->line('Assert: '.((($round['assertions']['passed'] ?? false) === true) ? 'passou' : 'falhou'));

            foreach ($round['assertions']['violations'] ?? [] as $violation) {
                $this->warn(sprintf(
                    '- %s | esperado=%s | atual=%s',
                    $violation['field'] ?? 'assertion',
                    json_encode($violation['expected'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    json_encode($violation['actual'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ));
            }

            $this->newLine();
        }

        $this->comment('Resultado geral: '.(($transcript['all_passed'] ?? false) ? 'passou' : 'com falhas'));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function persistOutputIfNeeded(array $payload): void
    {
        $path = $this->nullableOption('output');
        if ($path === null) {
            return;
        }

        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->comment("Transcript salvo em {$path}");
    }

    private function resolveExplicitUser(string $input, PhoneNumberService $phoneNumberService): ?User
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }

        if (ctype_digit($input)) {
            $user = User::find((int) $input);
            if ($user) {
                return $user;
            }
        }

        if (filter_var($input, FILTER_VALIDATE_EMAIL)) {
            return User::where('email', $input)->first();
        }

        return User::where('phone_number', $phoneNumberService->formatForStorage($input))->first();
    }

    private function resolveSingleSourceUser(Collection $entries): ?User
    {
        $userIds = $entries
            ->pluck('source_user_id')
            ->filter(fn ($value) => is_numeric($value))
            ->map(fn ($value) => (int) $value)
            ->unique()
            ->values();

        if ($userIds->count() !== 1) {
            return null;
        }

        return User::find($userIds->first());
    }

    private function resolvePhoneNumber(User $user, PhoneNumberService $phoneNumberService): ?string
    {
        $explicitPhone = $this->nullableOption('phone');
        if ($explicitPhone !== null) {
            return $phoneNumberService->formatForStorage($explicitPhone);
        }

        if (! empty($user->phone_number)) {
            return $phoneNumberService->formatForStorage((string) $user->phone_number);
        }

        return null;
    }

    private function nullableOption(string $name): ?string
    {
        $value = trim((string) ($this->option($name) ?? ''));

        return $value === '' ? null : $value;
    }
}
