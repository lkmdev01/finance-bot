<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use Illuminate\Console\Command;

class ProcessDueSubscriptions extends Command
{
    protected $signature = 'subscriptions:process-due';

    protected $description = 'Registra automaticamente assinaturas vencidas com auto registro ativo';

    public function handle(): int
    {
        $subscriptions = Subscription::query()
            ->where('is_active', true)
            ->where('auto_record', true)
            ->whereDate('next_due_date', '<=', now()->toDateString())
            ->get();

        $processed = 0;

        foreach ($subscriptions as $subscription) {
            $alreadyRecorded = $subscription->transactions()
                ->whereDate('date', $subscription->next_due_date)
                ->exists();

            if ($alreadyRecorded) {
                continue;
            }

            $subscription->markAsPaid($subscription->next_due_date?->copy() ?? now());
            $processed++;
        }

        $this->info("Processadas {$processed} assinatura(s).");

        return self::SUCCESS;
    }
}
