<?php

namespace App\Console\Commands;

use App\Models\RecurringTransaction;
use Illuminate\Console\Command;

class ProcessRecurringTransactions extends Command
{
    protected $signature = 'transactions:process-recurring';

    protected $description = 'Processa transações recorrentes e cria transações automáticas';

    public function handle(): int
    {
        $recurringTransactions = RecurringTransaction::where('is_active', true)->get();
        $processed = 0;

        foreach ($recurringTransactions as $recurring) {
            if ($recurring->shouldProcessToday()) {
                $recurring->user->transactions()->create([
                    'category_id' => $recurring->category_id,
                    'type' => $recurring->type,
                    'amount' => $recurring->amount,
                    'description' => $recurring->description ?? 'Transação recorrente',
                    'date' => now(),
                    'metadata' => [
                        'recurring_transaction_id' => $recurring->id,
                    ],
                ]);

                $recurring->update(['last_processed_at' => now()]);
                $processed++;
            }
        }

        $this->info("Processadas {$processed} transação(ões) recorrente(s).");

        return Command::SUCCESS;
    }
}
