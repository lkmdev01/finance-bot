<?php

namespace App\Console\Commands;

use App\Services\AlertService;
use Illuminate\Console\Command;

class CheckAlerts extends Command
{
    protected $signature = 'alerts:check';

    protected $description = 'Verifica condições de alerta e envia notificações';

    public function handle(AlertService $alertService): int
    {
        $this->info('Verificando alertas...');

        $alertService->checkAlerts();
        $alertService->checkExceededBudgets();

        $this->info('Verificação de alertas concluída.');

        return Command::SUCCESS;
    }
}
