<?php

namespace App\Console\Commands;

use App\Models\RecurringTransaction;
use Illuminate\Console\Command;

class ProcessRecurringTransactions extends Command
{
    protected $signature = 'transactions:process-recurring';

    protected $description = 'Processa transacoes recorrentes e cria transacoes automaticas';

    public function handle(): int
    {
        $recurringTransactions = RecurringTransaction::where('is_active', true)->get();
        $processed = 0;

        foreach ($recurringTransactions as $recurring) {
            if (! $recurring->shouldProcessToday()) {
                continue;
            }

            $recurring->user->transactions()->create([
                'category_id' => $recurring->category_id,
                'bank_account_id' => $recurring->bank_account_id,
                'credit_card_id' => $recurring->credit_card_id,
                'subscription_id' => $recurring->subscription_id,
                'type' => $recurring->type,
                'amount' => $recurring->amount,
                'description' => $recurring->description ?? 'Transacao recorrente',
                'date' => now(),
                'metadata' => [
                    'recurring_transaction_id' => $recurring->id,
                    'source' => 'recurring_transaction',
                ],
            ]);

            $recurring->update(['last_processed_at' => now()]);
            $processed++;
        }

        $this->info("Processadas {$processed} transacao(oes) recorrente(s).");

        return self::SUCCESS;
    }
}
