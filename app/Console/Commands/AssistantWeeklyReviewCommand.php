<?php

namespace App\Console\Commands;

use App\Assistant\Reports\AssistantObservabilityService;
use Illuminate\Console\Command;

class AssistantWeeklyReviewCommand extends Command
{
    protected $signature = 'assistant:weekly-review
        {--days=7 : Janela em dias}
        {--sample=1000 : Tamanho da amostra}
        {--focus=all : all|unknown|missing}
        {--sync : Sincroniza fixtures ao final da revisao}
        {--output= : Diretorio de saida das fixtures}';

    protected $description = 'Executa a revisao semanal operacional do assistente com resumo, backlog e sync opcional de fixtures.';

    public function handle(AssistantObservabilityService $observabilityService): int
    {
        $days = max(1, min(30, (int) $this->option('days')));
        $sample = max(10, min(5000, (int) $this->option('sample')));
        $focus = (string) $this->option('focus');

        $summary = $observabilityService->summary($days, $sample);

        $this->info("Revisao semanal do assistente ({$days} dias)");
        $this->line("Mensagens analisadas: {$summary['totals']['total']}");
        $this->line("Taxa de sucesso: {$summary['totals']['success_rate']}%");
        $this->line("Unknowns: {$summary['totals']['unknowns']}");
        $this->line("Chamadas com IA: {$summary['totals']['used_ai']}");
        $this->newLine();

        $this->line('Top intents:');
        foreach (array_slice($summary['by_intent'], 0, 5) as $row) {
            $this->line(sprintf(
                '- %s: total=%s | sucesso=%s%% | erros=%s | confianca=%s',
                $row['intent'],
                $row['total'],
                $row['success_rate'],
                $row['errors'],
                $row['avg_confidence']
            ));
        }

        $this->newLine();
        $this->line('Backlog priorizado:');
        foreach (array_slice($summary['regression_backlog'], 0, 10) as $item) {
            $this->line(sprintf(
                '- [%s] %s | dominio=%s | %sx | %s',
                $item['priority'],
                $item['intent'],
                $item['domain'] ?? 'unknown',
                $item['count'],
                $item['message']
            ));
        }

        if ($this->option('sync')) {
            $this->newLine();
            $this->info('Sincronizando fixtures...');

            $approvedItems = $observabilityService->backlogItems($days, $sample, $focus);
            $written = $observabilityService->syncFixtureFiles(
                days: $days,
                sampleSize: $sample,
                focus: $focus,
                outputDirectory: $this->option('output') ? (string) $this->option('output') : null,
            );

            if ($written === []) {
                $this->warn('Nenhuma fixture elegivel para sincronizar nesta revisao.');
            } else {
                foreach ($written as $domain => $path) {
                    $this->line("- {$domain}: {$path}");
                }

                $observabilityService->recordApprovalActivity($approvedItems, 'weekly_review');
                $observabilityService->recordSyncActivity('weekly_review', [
                    'days' => $days,
                    'focus' => $focus,
                    'domains' => array_keys($written),
                    'item_count' => count($approvedItems),
                ]);
            }
        }

        $observabilityService->recordReviewRun([
            'days' => $days,
            'sample' => $sample,
            'focus' => $focus,
            'sync' => (bool) $this->option('sync'),
            'backlog_count' => count($summary['regression_backlog'] ?? []),
        ]);

        $this->newLine();
        $this->comment('Fluxo sugerido: revisar unknowns, validar missing_fields, aprovar itens seletivos e depois sincronizar fixtures aprovadas.');

        return self::SUCCESS;
    }
}
