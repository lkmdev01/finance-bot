<?php

namespace App\Console\Commands;

use App\Services\QueueHeartbeatService;
use Illuminate\Console\Command;

class QueueHeartbeatCommand extends Command
{
    protected $signature = 'queue:heartbeat';

    protected $description = 'Dispatch a queue probe and report whether workers are processing probes.';

    public function handle(QueueHeartbeatService $heartbeat): int
    {
        $before = $heartbeat->snapshot();
        $dispatched = $heartbeat->dispatchProbe();
        $after = $heartbeat->snapshot();

        $this->line(sprintf(
            'Fila %s:%s heartbeat=%s estado=%s',
            $after['connection'],
            $after['queue'],
            $dispatched ? 'enviado' : 'pendente',
            $after['state'],
        ));

        if (! $before['healthy'] && $before['state'] !== 'not_initialized') {
            $this->error('O worker nao confirmou o heartbeat dentro do prazo esperado.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
