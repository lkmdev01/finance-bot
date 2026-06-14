<?php

namespace App\Services\WhatsApp;

use App\Models\BankAccount;
use App\Models\Budget;
use App\Models\Category;
use App\Models\DriveFile;
use App\Models\GoogleDriveConnection;
use App\Models\Note;
use App\Models\RecurringTransaction;
use App\Models\Reminder;
use App\Models\SavingsGoal;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WhatsAppContact;
use Illuminate\Support\Facades\DB;
use Throwable;

class SimulationSuiteService
{
    public function __construct(
        private readonly ConversationSimulationService $simulationService,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function suites(): array
    {
        $suites = require base_path('tests/Fixtures/whatsapp_simulation_suite.php');

        return is_array($suites) ? array_values($suites) : [];
    }

    /**
     * @param array<int, string> $suiteKeys
     * @return array<string, mixed>
     */
    public function runAll(array $suiteKeys = [], bool $persistData = false, bool $failFast = false): array
    {
        $availableSuites = collect($this->suites());
        $selectedSuites = $suiteKeys === []
            ? $availableSuites
            : $availableSuites->filter(fn (array $suite) => in_array((string) ($suite['key'] ?? ''), $suiteKeys, true))->values();

        $results = [];

        foreach ($selectedSuites as $suite) {
            $result = $this->runSuite($suite, $persistData);
            $results[] = $result;

            if ($failFast && ($result['passed'] ?? false) !== true) {
                break;
            }
        }

        $passedCount = collect($results)->where('passed', true)->count();

        return [
            'generated_at' => now()->toIso8601String(),
            'suite_count' => count($results),
            'available_suite_count' => $availableSuites->count(),
            'all_passed' => collect($results)->every(fn (array $result) => ($result['passed'] ?? false) === true),
            'passed_count' => $passedCount,
            'failed_count' => count($results) - $passedCount,
            'results' => $results,
        ];
    }

    /**
     * @param array<string, mixed> $suite
     * @return array<string, mixed>
     */
    public function runSuite(array $suite, bool $persistData = false): array
    {
        $suiteKey = (string) ($suite['key'] ?? 'suite');
        $result = [
            'key' => $suiteKey,
            'seed' => $suite['seed'] ?? null,
            'generated_at' => now()->toIso8601String(),
            'passed' => false,
            'violations' => [],
            'transcript' => null,
            'error' => null,
        ];

        if (! $persistData) {
            DB::beginTransaction();
        }

        try {
            $context = $this->initializeContext();
            $this->seedContext($context, $suite['seed'] ?? null);
            $entries = $this->resolveEntries($suite['entries'] ?? [], $context);

            $contact = $this->simulationService->prepareContact(
                $context['user'],
                $context['contact']->phone_number,
                'Suite Runner',
                true,
            );

            $transcript = $this->simulationService->simulate(
                $context['user'],
                $contact,
                $context['contact']->phone_number,
                $entries,
                ['push_name' => 'Suite Runner']
            );

            $violations = $this->collectViolations($suiteKey, $transcript);
            $violations = array_merge($violations, $this->validateSideEffects($context, $suiteKey));

            $result['passed'] = $violations === [];
            $result['violations'] = $violations;
            $result['transcript'] = $transcript;
        } catch (Throwable $throwable) {
            $result['error'] = [
                'type' => $throwable::class,
                'message' => $throwable->getMessage(),
            ];
            $result['violations'] = [[
                'suite' => $suiteKey,
                'field' => 'exception',
                'expected' => 'suite without exceptions',
                'actual' => $throwable->getMessage(),
            ]];
        } finally {
            if (! $persistData && DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function initializeContext(): array
    {
        static $sequence = 0;
        $sequence++;
        $phoneNumber = '5513999'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);

        $user = User::factory()->create([
            'phone_number' => $phoneNumber,
        ]);

        $contact = WhatsAppContact::factory()->create([
            'user_id' => $user->id,
            'phone_number' => $phoneNumber,
            'conversation_state' => [],
        ]);

        $expenseCategory = Category::factory()->create([
            'user_id' => $user->id,
            'type' => 'expense',
            'name' => 'Compras',
        ]);

        $subscriptionCategory = Category::factory()->create([
            'user_id' => $user->id,
            'type' => 'expense',
            'name' => 'Assinaturas',
        ]);

        $incomeCategory = Category::factory()->create([
            'user_id' => $user->id,
            'type' => 'income',
            'name' => 'Salario',
        ]);

        $cashAccount = BankAccount::create([
            'user_id' => $user->id,
            'name' => 'Caixa',
            'institution' => 'Dinheiro',
            'type' => 'cash',
            'opening_balance' => 500,
            'currency' => 'BRL',
            'color' => '#111111',
            'is_active' => true,
        ]);

        $nubankCard = \App\Models\CreditCard::create([
            'user_id' => $user->id,
            'name' => 'Nubank',
            'issuer' => 'Nubank',
            'brand' => 'Visa',
            'last_four' => '1234',
            'credit_limit' => 5000,
            'opening_balance' => 0,
            'closing_day' => 5,
            'due_day' => 25,
            'is_active' => true,
        ]);

        return compact(
            'user',
            'contact',
            'expenseCategory',
            'subscriptionCategory',
            'incomeCategory',
            'cashAccount',
            'nubankCard',
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function seedContext(array &$context, mixed $seed): void
    {
        switch ($seed) {
            case 'notes_and_reminders_queries':
                Note::create([
                    'user_id' => $context['user']->id,
                    'title' => 'Projeto Alpha',
                    'body' => 'Detalhes da reuniao com o cliente.',
                    'source' => 'whatsapp',
                    'metadata' => [],
                ]);

                Note::create([
                    'user_id' => $context['user']->id,
                    'title' => 'Projeto Beta',
                    'body' => 'Checklist de entrega.',
                    'source' => 'whatsapp',
                    'metadata' => [],
                ]);

                Reminder::query()->create([
                    'user_id' => $context['user']->id,
                    'title' => 'Pagar Academia',
                    'message' => 'Lembrete mensal: Pagar Academia',
                    'frequency' => 'monthly',
                    'timezone' => config('app.timezone'),
                    'next_trigger_at' => now()->addDays(3),
                    'day_of_month' => 10,
                    'trigger_time' => '09:00:00',
                    'is_active' => true,
                ]);

                Reminder::query()->create([
                    'user_id' => $context['user']->id,
                    'title' => 'Tomar Agua',
                    'message' => 'Lembrete diario: Tomar Agua',
                    'frequency' => 'daily',
                    'timezone' => config('app.timezone'),
                    'next_trigger_at' => now()->addDay(),
                    'trigger_time' => '08:00:00',
                    'is_active' => true,
                ]);
                break;

            case 'planning_queries':
                SavingsGoal::create([
                    'user_id' => $context['user']->id,
                    'name' => 'Viagem',
                    'target_amount' => 5000,
                    'target_date' => now()->addMonths(8)->toDateString(),
                    'is_completed' => false,
                ]);

                SavingsGoal::create([
                    'user_id' => $context['user']->id,
                    'name' => 'Reserva',
                    'target_amount' => 10000,
                    'target_date' => now()->addMonths(12)->toDateString(),
                    'is_completed' => false,
                ]);

                Subscription::create([
                    'user_id' => $context['user']->id,
                    'category_id' => $context['subscriptionCategory']->id,
                    'name' => 'Netflix',
                    'amount' => 39.90,
                    'billing_cycle' => 'monthly',
                    'due_day' => 10,
                    'start_date' => now()->subMonths(2)->toDateString(),
                    'next_due_date' => now()->addDays(4)->toDateString(),
                    'is_active' => true,
                ]);

                Subscription::create([
                    'user_id' => $context['user']->id,
                    'category_id' => $context['subscriptionCategory']->id,
                    'name' => 'Spotify',
                    'amount' => 21.90,
                    'billing_cycle' => 'monthly',
                    'due_day' => 18,
                    'start_date' => now()->subMonths(2)->toDateString(),
                    'next_due_date' => now()->addDays(8)->toDateString(),
                    'is_active' => true,
                ]);

                RecurringTransaction::create([
                    'user_id' => $context['user']->id,
                    'category_id' => $context['expenseCategory']->id,
                    'type' => 'expense',
                    'amount' => 1200,
                    'description' => 'Aluguel',
                    'frequency' => 'monthly',
                    'start_date' => now()->toDateString(),
                    'day_of_month' => 5,
                    'is_active' => true,
                ]);

                RecurringTransaction::create([
                    'user_id' => $context['user']->id,
                    'category_id' => $context['expenseCategory']->id,
                    'type' => 'expense',
                    'amount' => 89,
                    'description' => 'Academia',
                    'frequency' => 'monthly',
                    'start_date' => now()->toDateString(),
                    'day_of_month' => 10,
                    'is_active' => true,
                ]);
                break;

            case 'mvp_acceptance':
                SavingsGoal::create([
                    'user_id' => $context['user']->id,
                    'name' => 'De Viagem',
                    'target_amount' => 5000,
                    'target_date' => null,
                    'is_completed' => false,
                ]);

                SavingsGoal::create([
                    'user_id' => $context['user']->id,
                    'name' => 'Viagem',
                    'target_amount' => 300,
                    'target_date' => null,
                    'is_completed' => false,
                ]);

                SavingsGoal::create([
                    'user_id' => $context['user']->id,
                    'name' => 'Viagem - R$ 5.000,00',
                    'target_amount' => 5000,
                    'target_date' => null,
                    'is_completed' => false,
                ]);

                SavingsGoal::create([
                    'user_id' => $context['user']->id,
                    'name' => 'Viagem Asia',
                    'target_amount' => 1500,
                    'target_date' => null,
                    'is_completed' => false,
                ]);

                Note::create([
                    'user_id' => $context['user']->id,
                    'title' => 'Tive Uma Ideia De Função De Gravar Arquivos No Drive Atraves Da Inovafinance',
                    'body' => 'tive uma ideia de função de gravar arquivos no drive atraves da inovafinance',
                    'source' => 'whatsapp',
                    'metadata' => [],
                ]);

                GoogleDriveConnection::create([
                    'user_id' => $context['user']->id,
                    'refresh_token' => 'fake-refresh-token',
                    'scopes' => ['https://www.googleapis.com/auth/drive.file'],
                    'root_folder_id' => 'root-folder-id',
                    'connected_at' => now(),
                ]);

                DriveFile::create([
                    'user_id' => $context['user']->id,
                    'source' => 'whatsapp',
                    'original_name' => 'comprovante_mecanico.pdf',
                    'mime_type' => 'application/pdf',
                    'size_bytes' => 285500,
                    'sha256' => hash('sha256', 'mvp-comprovante-mecanico'),
                    'drive_file_id' => 'mvp-drive-file-1',
                    'drive_parent_id' => 'mvp-drive-parent-1',
                    'drive_path' => 'Comprovantes / Veiculos',
                    'title' => 'comprovante_mecanico',
                    'description' => 'Comprovante do mecanico',
                    'tags' => ['comprovante', 'veiculo'],
                    'extracted_text' => 'servico mecanico realizado',
                    'metadata' => [],
                ]);

                DriveFile::create([
                    'user_id' => $context['user']->id,
                    'source' => 'whatsapp',
                    'original_name' => 'foto_neve.png',
                    'mime_type' => 'image/png',
                    'size_bytes' => 91500,
                    'sha256' => hash('sha256', 'mvp-foto-neve'),
                    'drive_file_id' => 'mvp-drive-file-2',
                    'drive_parent_id' => 'mvp-drive-parent-2',
                    'drive_path' => 'Fotos / Viagens',
                    'title' => 'foto_neve',
                    'description' => 'Foto na neve durante a viagem',
                    'tags' => ['foto', 'neve', 'viagem'],
                    'extracted_text' => 'paisagem com neve e montanha',
                    'metadata' => [],
                ]);
                break;

            case 'transaction_and_budget_context':
                $context['uberTransaction'] = Transaction::create([
                    'user_id' => $context['user']->id,
                    'whatsapp_contact_id' => $context['contact']->id,
                    'category_id' => $context['expenseCategory']->id,
                    'bank_account_id' => $context['cashAccount']->id,
                    'type' => 'expense',
                    'amount' => 20,
                    'description' => 'Uber',
                    'date' => now()->toDateString(),
                ]);

                $context['comprasBudget'] = Budget::create([
                    'user_id' => $context['user']->id,
                    'category_id' => $context['expenseCategory']->id,
                    'amount' => 500,
                    'period' => 'monthly',
                    'year' => now()->year,
                    'month' => now()->month,
                ]);
                break;

            case 'drive_queries':
                GoogleDriveConnection::create([
                    'user_id' => $context['user']->id,
                    'refresh_token' => 'fake-refresh-token',
                    'scopes' => ['https://www.googleapis.com/auth/drive.file'],
                    'root_folder_id' => 'root-folder-id',
                    'connected_at' => now(),
                ]);

                DriveFile::create([
                    'user_id' => $context['user']->id,
                    'source' => 'whatsapp',
                    'original_name' => 'comprovante_mecanico.pdf',
                    'mime_type' => 'application/pdf',
                    'size_bytes' => 285500,
                    'sha256' => hash('sha256', 'comprovante-mecanico'),
                    'drive_file_id' => 'drive-file-1',
                    'drive_parent_id' => 'drive-parent-1',
                    'drive_path' => 'Comprovantes / Veiculos',
                    'title' => 'comprovante_mecanico',
                    'description' => 'Comprovante do mecanico de marco',
                    'tags' => ['comprovante', 'veiculo'],
                    'extracted_text' => 'servico mecanico realizado em marco',
                    'metadata' => [],
                    'created_at' => now()->subDays(12),
                    'updated_at' => now()->subDays(12),
                ]);

                DriveFile::create([
                    'user_id' => $context['user']->id,
                    'source' => 'whatsapp',
                    'original_name' => 'contrato_aluguel.pdf',
                    'mime_type' => 'application/pdf',
                    'size_bytes' => 128000,
                    'sha256' => hash('sha256', 'contrato-aluguel'),
                    'drive_file_id' => 'drive-file-2',
                    'drive_parent_id' => 'drive-parent-2',
                    'drive_path' => 'Documentos / Contratos',
                    'title' => 'contrato_aluguel',
                    'description' => 'Contrato do apartamento',
                    'tags' => ['contrato'],
                    'extracted_text' => 'contrato de locacao residencial',
                    'metadata' => [],
                    'created_at' => now()->subDays(7),
                    'updated_at' => now()->subDays(7),
                ]);

                DriveFile::create([
                    'user_id' => $context['user']->id,
                    'source' => 'whatsapp',
                    'original_name' => 'foto_neve.png',
                    'mime_type' => 'image/png',
                    'size_bytes' => 91500,
                    'sha256' => hash('sha256', 'foto-neve'),
                    'drive_file_id' => 'drive-file-3',
                    'drive_parent_id' => 'drive-parent-3',
                    'drive_path' => 'Fotos / Viagens',
                    'title' => 'foto_neve',
                    'description' => 'Foto na neve durante a viagem',
                    'tags' => ['foto', 'neve', 'viagem'],
                    'extracted_text' => 'paisagem com neve e montanha',
                    'metadata' => [],
                    'created_at' => now()->startOfDay()->addHours(9),
                    'updated_at' => now()->startOfDay()->addHours(9),
                ]);

                DriveFile::create([
                    'user_id' => $context['user']->id,
                    'source' => 'whatsapp',
                    'original_name' => 'recibo_oficina.png',
                    'mime_type' => 'image/png',
                    'size_bytes' => 88300,
                    'sha256' => hash('sha256', 'recibo-oficina'),
                    'drive_file_id' => 'drive-file-4',
                    'drive_parent_id' => 'drive-parent-4',
                    'drive_path' => 'Comprovantes / Veiculos',
                    'title' => 'recibo_oficina',
                    'description' => 'Recibo da oficina do carro',
                    'tags' => ['foto', 'oficina', 'veiculo'],
                    'extracted_text' => 'recibo oficina troca de oleo',
                    'metadata' => [],
                    'created_at' => now()->subDays(1)->startOfDay()->addHours(15),
                    'updated_at' => now()->subDays(1)->startOfDay()->addHours(15),
                ]);

                DriveFile::create([
                    'user_id' => $context['user']->id,
                    'source' => 'whatsapp',
                    'original_name' => 'audio_projeto.mp3',
                    'mime_type' => 'audio/mpeg',
                    'size_bytes' => 2048000,
                    'sha256' => hash('sha256', 'audio-projeto'),
                    'drive_file_id' => 'drive-file-5',
                    'drive_parent_id' => 'drive-parent-5',
                    'drive_path' => 'Audios / Projetos',
                    'title' => 'audio_projeto',
                    'description' => 'Audio com ideias sobre o projeto',
                    'tags' => ['audio', 'projeto', 'ideias'],
                    'extracted_text' => 'brainstorm do projeto de expansao',
                    'metadata' => [],
                    'created_at' => now()->startOfDay()->addHours(11),
                    'updated_at' => now()->startOfDay()->addHours(11),
                ]);
                break;

            case 'recurring_cancel_flow':
                RecurringTransaction::create([
                    'user_id' => $context['user']->id,
                    'category_id' => $context['expenseCategory']->id,
                    'type' => 'expense',
                    'amount' => 1200,
                    'description' => 'Aluguel',
                    'frequency' => 'monthly',
                    'start_date' => now()->toDateString(),
                    'day_of_month' => 5,
                    'is_active' => true,
                ]);
                break;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>
     */
    private function resolveEntries(array $entries, array $context): array
    {
        return collect($entries)
            ->map(function (array $entry) use ($context) {
                $replacements = [
                    '__transaction_uber__' => (string) ($context['uberTransaction']->id ?? ''),
                    '__budget_compras__' => (string) ($context['comprasBudget']->id ?? ''),
                    '__current_year__' => (string) now()->year,
                    '__current_month__' => (string) now()->month,
                ];

                return $this->replaceTokens($entry, $replacements);
            })
            ->all();
    }

    /**
     * @param array<string, string> $replacements
     */
    private function replaceTokens(mixed $value, array $replacements): mixed
    {
        if (is_string($value)) {
            return $replacements[$value] ?? $value;
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->replaceTokens($item, $replacements);
            }
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $transcript
     * @return array<int, array<string, mixed>>
     */
    private function collectViolations(string $suiteKey, array $transcript): array
    {
        return collect($transcript['rounds'] ?? [])
            ->flatMap(function (array $round) use ($suiteKey) {
                return collect($round['assertions']['violations'] ?? [])
                    ->map(fn (array $violation) => array_merge([
                        'suite' => $suiteKey,
                        'label' => $round['label'] ?? null,
                        'message' => $round['input']['message'] ?? null,
                    ], $violation));
            })
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>
     */
    private function validateSideEffects(array $context, string $suiteKey): array
    {
        $violations = [];

        switch ($suiteKey) {
            case 'finance_core':
                if (! Transaction::query()->where('user_id', $context['user']->id)->where('type', 'expense')->exists()) {
                    $violations[] = $this->sideEffectViolation($suiteKey, 'expense transaction created');
                }

                if (! Transaction::query()->where('user_id', $context['user']->id)->where('type', 'income')->exists()) {
                    $violations[] = $this->sideEffectViolation($suiteKey, 'income transaction created');
                }
                break;

            case 'notes_and_reminders_create':
                if (Note::query()->where('user_id', $context['user']->id)->count() === 0) {
                    $violations[] = $this->sideEffectViolation($suiteKey, 'note created');
                }

                if (Reminder::query()->where('user_id', $context['user']->id)->where('is_active', true)->count() === 0) {
                    $violations[] = $this->sideEffectViolation($suiteKey, 'active reminder created');
                }
                break;

            case 'planning_creations':
                if (! SavingsGoal::query()->where('user_id', $context['user']->id)->where('name', 'Viagem')->exists()) {
                    $violations[] = $this->sideEffectViolation($suiteKey, 'savings goal Viagem created');
                }

                if (! Subscription::query()->where('user_id', $context['user']->id)->where('name', 'Netflix')->exists()) {
                    $violations[] = $this->sideEffectViolation($suiteKey, 'subscription Netflix created');
                }
                break;

            case 'transaction_and_budget_context':
                $transaction = isset($context['uberTransaction']) ? Transaction::query()->find($context['uberTransaction']->id) : null;

                if (! $transaction || (float) $transaction->amount !== 28.0 || $transaction->credit_card_id !== $context['nubankCard']->id) {
                    $violations[] = [
                        'suite' => $suiteKey,
                        'field' => 'side_effect',
                        'expected' => 'transaction updated to 28.0 on Nubank',
                        'actual' => $transaction ? [
                            'amount' => (float) $transaction->amount,
                            'credit_card_id' => $transaction->credit_card_id,
                        ] : null,
                    ];
                }

                if (isset($context['comprasBudget']) && Budget::query()->find($context['comprasBudget']->id) !== null) {
                    $violations[] = [
                        'suite' => $suiteKey,
                        'field' => 'side_effect',
                        'expected' => 'budget deleted after confirmation',
                        'actual' => 'budget still exists',
                    ];
                }
                break;

            case 'recurring_cancel_flow':
                $recurring = RecurringTransaction::query()
                    ->where('user_id', $context['user']->id)
                    ->where('description', 'Aluguel')
                    ->first();

                if (! $recurring || $recurring->is_active !== false) {
                    $violations[] = [
                        'suite' => $suiteKey,
                        'field' => 'side_effect',
                        'expected' => 'recurring transaction Aluguel canceled',
                        'actual' => $recurring?->is_active,
                    ];
                }
                break;
        }

        return $violations;
    }

    /**
     * @return array<string, mixed>
     */
    private function sideEffectViolation(string $suiteKey, string $expected): array
    {
        return [
            'suite' => $suiteKey,
            'field' => 'side_effect',
            'expected' => $expected,
            'actual' => null,
        ];
    }
}
