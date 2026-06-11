<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\PhoneNumberService;
use App\Services\WhatsApp\ConversationSimulationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class SimulateWhatsAppConversationCommand extends Command
{
    protected $signature = 'whatsapp:simulate
        {user? : ID, email ou telefone do usuario}
        {--message=* : Mensagens para processar em lote}
        {--file= : Arquivo .txt ou .json com as mensagens}
        {--phone= : Numero a simular (padrao: telefone do usuario)}
        {--push-name=Codex Local : Nome exibido no remetente}
        {--remote-jid= : JID remoto customizado}
        {--reset-state : Limpa o estado da conversa antes de iniciar}
        {--show-log : Exibe o ultimo log conversacional apos cada mensagem}
        {--json : Imprime o transcript final em JSON}
        {--transcript-out= : Salva o transcript final em arquivo .json}
        {--assert : Falha se alguma expectativa do transcript nao for atendida}';

    protected $description = 'Simula conversas reais do WhatsApp localmente sem enviar mensagens para o provedor.';

    public function handle(PhoneNumberService $phoneNumberService, ConversationSimulationService $simulationService): int
    {
        $user = $this->resolveUser((string) ($this->argument('user') ?? ''), $phoneNumberService);

        if (! $user && $this->option('phone')) {
            $user = $this->resolveUser((string) $this->option('phone'), $phoneNumberService);
        }

        if (! $user) {
            $this->error('Usuario nao encontrado.');
            $this->renderUserSuggestions();

            return self::FAILURE;
        }

        $phoneNumber = $this->resolvePhoneNumber($user, $phoneNumberService);

        if ($phoneNumber === null) {
            $this->error('Nao consegui determinar o telefone para a simulacao. Use --phone=5511...');
            return self::FAILURE;
        }

        $contact = $simulationService->prepareContact(
            $user,
            $phoneNumber,
            (string) $this->option('push-name'),
            (bool) $this->option('reset-state'),
        );

        $entries = $this->loadEntries();

        if ($entries === []) {
            return $this->runInteractive($user, $contact->phone_number, $simulationService);
        }

        $transcript = $simulationService->simulate($user, $contact, $phoneNumber, $entries, [
            'push_name' => (string) $this->option('push-name'),
            'remote_jid' => (string) ($this->option('remote-jid') ?? ''),
        ]);

        $this->renderTranscript($transcript);
        $this->persistTranscriptIfNeeded($transcript);

        if ($this->option('assert') && ($transcript['all_passed'] ?? false) !== true) {
            $this->error('Transcript executado, mas houve falhas nas expectativas.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function runInteractive(User $user, string $phoneNumber, ConversationSimulationService $simulationService): int
    {
        $this->info("Simulacao iniciada para {$user->email} (#{$user->id})");
        $this->line("Telefone: {$phoneNumber}");
        $this->comment('Digite a mensagem e pressione Enter. Comandos: :state, :reset, :quit');
        $this->newLine();

        $round = 1;

        while (true) {
            $input = trim((string) $this->ask("Mensagem {$round}"));

            if ($input === '') {
                continue;
            }

            if ($input === ':quit') {
                $this->comment('Simulacao encerrada.');
                return self::SUCCESS;
            }

            if ($input === ':state') {
                $contact = $simulationService->prepareContact($user, $phoneNumber, (string) $this->option('push-name'));
                $state = is_array($contact->conversation_state) ? $contact->conversation_state : [];
                $entities = is_array($state['last_entities'] ?? null) ? $state['last_entities'] : [];
                $this->comment(sprintf(
                    'Estado: action=%s | topic=%s | pending=%s',
                    $state['last_action'] ?? 'n/a',
                    $entities['topic'] ?? 'n/a',
                    $state['pending_intent'] ?? 'n/a',
                ));
                continue;
            }

            if ($input === ':reset') {
                $simulationService->prepareContact($user, $phoneNumber, (string) $this->option('push-name'), true);
                $this->comment('Estado da conversa limpo.');
                continue;
            }

            $contact = $simulationService->prepareContact($user, $phoneNumber, (string) $this->option('push-name'));
            $transcript = $simulationService->simulate($user, $contact, $phoneNumber, [[
                'message' => $input,
            ]], [
                'push_name' => (string) $this->option('push-name'),
                'remote_jid' => (string) ($this->option('remote-jid') ?? ''),
            ]);

            $this->renderTranscript($transcript);
            $round++;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadEntries(): array
    {
        $entries = [];

        foreach ((array) $this->option('message') as $message) {
            $message = trim((string) $message);
            if ($message === '') {
                continue;
            }

            $entries[] = ['message' => $message];
        }

        $file = trim((string) ($this->option('file') ?? ''));
        if ($file === '') {
            return $entries;
        }

        if (! is_file($file)) {
            $this->warn("Arquivo nao encontrado: {$file}");

            return $entries;
        }

        $extension = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));

        if ($extension === 'json') {
            $decoded = json_decode((string) file_get_contents($file), true);

            if (! is_array($decoded)) {
                $this->warn("Nao consegui ler o JSON de {$file}.");

                return $entries;
            }

            $items = is_array($decoded['entries'] ?? null) ? $decoded['entries'] : $decoded;

            foreach ($items as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $entries[] = ['message' => trim($item)];
                    continue;
                }

                if (! is_array($item) || trim((string) ($item['message'] ?? '')) === '') {
                    continue;
                }

                $entries[] = $item;
            }

            return $entries;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $entries[] = ['message' => $line];
        }

        return $entries;
    }

    /**
     * @param array<string, mixed> $transcript
     */
    private function renderTranscript(array $transcript): void
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

            $state = is_array($round['state'] ?? null) ? $round['state'] : [];
            $entities = is_array($state['last_entities'] ?? null) ? $state['last_entities'] : [];
            $this->comment(sprintf(
                'Estado: action=%s | topic=%s | pending=%s',
                $state['last_action'] ?? 'n/a',
                $entities['topic'] ?? 'n/a',
                $state['pending_intent'] ?? 'n/a',
            ));

            if ($this->option('show-log') && ! empty($round['log'])) {
                $log = $round['log'];
                $this->line(sprintf(
                    'Log: status=%s | action=%s | intent=%s | handler=%s | used_ai=%s',
                    $log['status'] ?? 'n/a',
                    $log['action'] ?? 'n/a',
                    $log['assistant_intent'] ?? 'n/a',
                    $log['handler'] ?? 'n/a',
                    ! empty($log['used_ai']) ? 'yes' : 'no',
                ));
            }

            if ($this->option('assert')) {
                $assertions = $round['assertions'] ?? ['passed' => true, 'violations' => []];
                $this->line('Assert: '.(($assertions['passed'] ?? false) ? 'passou' : 'falhou'));

                foreach ($assertions['violations'] ?? [] as $violation) {
                    $this->warn(sprintf(
                        '- %s | esperado=%s | atual=%s',
                        $violation['field'] ?? 'assertion',
                        json_encode($violation['expected'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        json_encode($violation['actual'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ));
                }
            }

            $this->newLine();
        }

        if (array_key_exists('all_passed', $transcript)) {
            $this->comment('Resultado geral: '.(($transcript['all_passed'] ?? false) ? 'passou' : 'com falhas'));
        }
    }

    /**
     * @param array<string, mixed> $transcript
     */
    private function persistTranscriptIfNeeded(array $transcript): void
    {
        $targetPath = trim((string) ($this->option('transcript-out') ?? ''));
        if ($targetPath === '') {
            return;
        }

        $directory = dirname($targetPath);
        File::ensureDirectoryExists($directory);
        file_put_contents($targetPath, json_encode($transcript, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->comment("Transcript salvo em {$targetPath}");
    }

    private function resolveUser(string $input, PhoneNumberService $phoneNumberService): ?User
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

        $normalizedPhone = $phoneNumberService->formatForStorage($input);

        return User::where('phone_number', $normalizedPhone)->first();
    }

    private function resolvePhoneNumber(User $user, PhoneNumberService $phoneNumberService): ?string
    {
        $explicitPhone = trim((string) ($this->option('phone') ?? ''));
        if ($explicitPhone !== '') {
            return $phoneNumberService->formatForStorage($explicitPhone);
        }

        if (! empty($user->phone_number)) {
            return $phoneNumberService->formatForStorage((string) $user->phone_number);
        }

        return null;
    }

    private function renderUserSuggestions(): void
    {
        $users = User::query()
            ->select('id', 'email', 'phone_number')
            ->latest('id')
            ->limit(5)
            ->get();

        if ($users->isEmpty()) {
            $this->line('Nenhum usuario local encontrado.');

            return;
        }

        $this->line('Sugestoes de usuarios locais:');
        foreach ($users as $user) {
            $this->line(sprintf(
                '- id=%d | email=%s | phone=%s',
                $user->id,
                $user->email ?? 'sem email',
                $user->phone_number ?? 'sem telefone',
            ));
        }
    }
}
