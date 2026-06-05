<?php

namespace App\Console\Commands;

use App\Assistant\Reports\AssistantObservabilityService;
use Illuminate\Console\Command;

class SyncAssistantObservabilityFixtures extends Command
{
    protected $signature = 'assistant:sync-observability-fixtures
        {--days=14 : Janela em dias}
        {--sample=1000 : Tamanho da amostra}
        {--focus=all : all|unknown|missing}
        {--output= : Diretorio de saida}';

    protected $description = 'Gera fixtures de regressao do assistente por dominio em tests/Fixtures/generated.';

    public function handle(AssistantObservabilityService $observabilityService): int
    {
        $files = $observabilityService->syncFixtureFiles(
            days: max(1, min(30, (int) $this->option('days'))),
            sampleSize: max(10, min(5000, (int) $this->option('sample'))),
            focus: (string) $this->option('focus'),
            outputDirectory: $this->option('output') ? (string) $this->option('output') : null,
        );

        if ($files === []) {
            $this->warn('Nenhum backlog disponivel para gerar fixtures.');

            return self::SUCCESS;
        }

        foreach ($files as $domain => $path) {
            $this->line("{$domain}: {$path}");
        }

        $this->info('Fixtures sincronizadas com sucesso.');

        return self::SUCCESS;
    }
}
