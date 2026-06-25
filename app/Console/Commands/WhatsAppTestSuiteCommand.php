<?php

namespace App\Console\Commands;

use App\Services\WhatsApp\SimulationSuiteService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class WhatsAppTestSuiteCommand extends Command
{
    protected $signature = 'whatsapp:test-suite
        {--suite=* : Executa apenas as suites informadas}
        {--domain=* : Executa apenas suites do dominio informado, ex: finance, drive, notes}
        {--output-dir= : Diretorio onde os transcripts serao exportados}
        {--keep-data : Mantem os registros criados em vez de rollback}
        {--fail-fast : Para na primeira suite com falha}
        {--json : Imprime o resumo final em JSON}
        {--use-current-db : Usa o banco atual em vez de um SQLite temporario isolado}
        {--list : Lista as suites disponiveis}';

    protected $description = 'Executa a bateria automatica de cenarios reais do WhatsApp e exporta transcripts JSON por journey.';

    public function handle(SimulationSuiteService $suiteService): int
    {
        if ($this->option('list')) {
            foreach ($suiteService->suites() as $suite) {
                $this->line(sprintf(
                    '[%s] %s%s',
                    (string) ($suite['domain'] ?? 'general'),
                    (string) ($suite['key'] ?? 'suite'),
                    filled($suite['title'] ?? null) ? ' - '.(string) $suite['title'] : '',
                ));
            }

            return self::SUCCESS;
        }

        $databaseContext = null;

        try {
            if (! $this->option('use-current-db')) {
                $databaseContext = $this->bootIsolatedDatabase();
            }

            $suiteKeys = array_values(array_filter(
                array_map('trim', (array) $this->option('suite')),
                fn (string $value) => $value !== ''
            ));
            $domains = array_values(array_filter(
                array_map('trim', (array) $this->option('domain')),
                fn (string $value) => $value !== ''
            ));

            $report = $suiteService->runAll(
                suiteKeys: $suiteKeys,
                domains: $domains,
                persistData: (bool) $this->option('keep-data'),
                failFast: (bool) $this->option('fail-fast'),
            );

            $outputDirectory = $this->resolveOutputDirectory();
            $this->persistReport($report, $outputDirectory);

            if ($this->option('json')) {
                $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } else {
                $this->renderSummary($report, $outputDirectory);
            }

            return ($report['all_passed'] ?? false) === true
                ? self::SUCCESS
                : self::FAILURE;
        } finally {
            if (is_array($databaseContext)) {
                $this->restoreDatabaseContext($databaseContext);
            }
        }
    }

    private function resolveOutputDirectory(): string
    {
        $explicit = trim((string) ($this->option('output-dir') ?? ''));

        if ($explicit !== '') {
            return $explicit;
        }

        return storage_path('app/testing/whatsapp-suite/'.now()->format('Ymd_His'));
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function persistReport(array $report, string $outputDirectory): void
    {
        File::ensureDirectoryExists($outputDirectory);

        file_put_contents(
            $outputDirectory.DIRECTORY_SEPARATOR.'summary.json',
            json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        foreach ($report['results'] ?? [] as $index => $result) {
            $key = preg_replace('/[^a-z0-9_-]+/i', '-', (string) ($result['key'] ?? 'suite')) ?: 'suite';
            $fileName = sprintf('%02d_%s.json', $index + 1, strtolower($key));

            file_put_contents(
                $outputDirectory.DIRECTORY_SEPARATOR.$fileName,
                json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderSummary(array $report, string $outputDirectory): void
    {
        $this->info('WhatsApp test suite executada.');
        $this->line('Suites executadas: '.($report['suite_count'] ?? 0));
        $this->line('Dominios: '.implode(', ', $report['domains'] ?? []));
        $this->line('Passaram: '.($report['passed_count'] ?? 0));
        $this->line('Falharam: '.($report['failed_count'] ?? 0));
        $this->line("Transcripts: {$outputDirectory}");
        $this->newLine();

        foreach ($report['results'] ?? [] as $result) {
            $status = (($result['passed'] ?? false) === true) ? 'PASS' : 'FAIL';
            $this->line(sprintf(
                '[%s] [%s] %s',
                $status,
                (string) ($result['domain'] ?? 'general'),
                (string) ($result['key'] ?? 'suite')
            ));

            foreach ($result['violations'] ?? [] as $violation) {
                $this->warn(sprintf(
                    '  - %s | esperado=%s | atual=%s',
                    $violation['field'] ?? 'assertion',
                    json_encode($violation['expected'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    json_encode($violation['actual'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ));
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function bootIsolatedDatabase(): array
    {
        $connectionName = 'whatsapp_suite_runtime';
        $tempPath = storage_path('app/testing/whatsapp-suite-runtime-'.getmypid().'-'.str_replace('.', '', uniqid('', true)).'.sqlite');
        $originalDefault = config('database.default');
        $originalConnection = config("database.connections.{$connectionName}");

        File::ensureDirectoryExists(dirname($tempPath));
        if (File::exists($tempPath)) {
            File::delete($tempPath);
        }
        File::put($tempPath, '');

        config([
            "database.connections.{$connectionName}" => [
                'driver' => 'sqlite',
                'database' => $tempPath,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'database.default' => $connectionName,
        ]);

        DB::purge($connectionName);
        DB::setDefaultConnection($connectionName);

        $exitCode = Artisan::call('migrate:fresh', [
            '--database' => $connectionName,
            '--force' => true,
        ]);

        if ($exitCode !== self::SUCCESS) {
            throw new \RuntimeException('Nao consegui preparar o banco temporario da suite: '.trim(Artisan::output()));
        }

        if (! $this->option('json')) {
            $this->comment('Usando banco SQLite temporario isolado para a suite.');
        }

        return [
            'connection_name' => $connectionName,
            'temp_path' => $tempPath,
            'original_default' => $originalDefault,
            'original_connection' => $originalConnection,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function restoreDatabaseContext(array $context): void
    {
        $connectionName = (string) ($context['connection_name'] ?? 'whatsapp_suite_runtime');

        DB::disconnect($connectionName);
        DB::purge($connectionName);

        config([
            'database.default' => $context['original_default'] ?? config('database.default'),
            "database.connections.{$connectionName}" => $context['original_connection'],
        ]);

        DB::setDefaultConnection((string) ($context['original_default'] ?? config('database.default')));

        $tempPath = (string) ($context['temp_path'] ?? '');
        if ($tempPath !== '' && File::exists($tempPath)) {
            File::delete($tempPath);
        }
    }
}
