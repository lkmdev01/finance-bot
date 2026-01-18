<?php

namespace App\Console\Commands;

use App\Services\TransactionImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

class ImportTransactions extends Command
{
    protected $signature = 'transactions:import {user_id} {file} {--format=csv}';

    protected $description = 'Importa transações de arquivo CSV ou OFX';

    public function handle(TransactionImportService $importService): int
    {
        $userId = $this->argument('user_id');
        $filePath = $this->argument('file');
        $format = $this->option('format');

        if (! file_exists($filePath)) {
            $this->error("Arquivo não encontrado: {$filePath}");

            return 1;
        }

        $user = \App\Models\User::findOrFail($userId);

        $this->info("Importando transações de {$format}...");

        if ($format === 'csv') {
            $result = $importService->importFromCsv($user, $filePath);
        } else {
            $result = $importService->importFromOfx($user, $filePath);
        }

        $this->info("Importadas: {$result['imported']} transações");

        if (count($result['errors']) > 0) {
            $this->warn('Erros encontrados: '.count($result['errors']));
            foreach ($result['errors'] as $error) {
                $this->error("Linha: ".json_encode($error['row'])." - {$error['error']}");
            }
        }

        return 0;
    }
}
