<?php

namespace App\Console\Commands;

use App\Models\DriveFile;
use Illuminate\Console\Command;

class DriveMetadataReportCommand extends Command
{
    protected $signature = 'drive:metadata-report
        {--user= : Filtra por user_id}
        {--failed : Lista arquivos com metadata_status=failed}
        {--reset-failed : Marca arquivos failed como pending para reprocessamento futuro}';

    protected $description = 'Mostra observabilidade da analise semantica dos arquivos do Drive.';

    public function handle(): int
    {
        $query = DriveFile::query();
        $userId = (int) ($this->option('user') ?? 0);

        if ($userId > 0) {
            $query->where('user_id', $userId);
        }

        if ($this->option('reset-failed')) {
            $updated = (clone $query)
                ->where('metadata_status', 'failed')
                ->update([
                    'metadata_status' => 'pending',
                    'metadata_error' => null,
                    'metadata_analyzed_at' => null,
                ]);

            $this->info("Arquivos marcados como pending: {$updated}");
        }

        $counts = (clone $query)
            ->selectRaw('metadata_status, count(*) as total')
            ->groupBy('metadata_status')
            ->orderBy('metadata_status')
            ->pluck('total', 'metadata_status')
            ->all();

        $this->info('Status de metadata do Drive');
        foreach (['pending', 'completed', 'failed', 'unavailable'] as $status) {
            $this->line(sprintf('- %s: %d', $status, (int) ($counts[$status] ?? 0)));
        }

        if ($this->option('failed')) {
            $failed = (clone $query)
                ->where('metadata_status', 'failed')
                ->latest('id')
                ->limit(20)
                ->get(['id', 'user_id', 'title', 'original_name', 'metadata_error', 'created_at']);

            if ($failed->isEmpty()) {
                $this->line('Nenhuma falha recente.');

                return self::SUCCESS;
            }

            $this->table(
                ['id', 'user_id', 'arquivo', 'erro', 'created_at'],
                $failed->map(fn (DriveFile $file) => [
                    $file->id,
                    $file->user_id,
                    $file->title ?: $file->original_name,
                    str((string) $file->metadata_error)->limit(120)->toString(),
                    $file->created_at?->toDateTimeString(),
                ])->all()
            );
        }

        return self::SUCCESS;
    }
}
