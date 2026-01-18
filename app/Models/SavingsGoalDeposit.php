<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavingsGoalDeposit extends Model
{
    /** @use HasFactory<\Database\Factories\SavingsGoalDepositFactory> */
    use HasFactory;

    protected $fillable = [
        'savings_goal_id',
        'amount',
        'description',
        'deposit_date',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'deposit_date' => 'date',
        ];
    }

    public function savingsGoal(): BelongsTo
    {
        return $this->belongsTo(SavingsGoal::class);
    }

    protected static function boot(): void
    {
        parent::boot();

        static::created(function (SavingsGoalDeposit $deposit) {
            $goal = $deposit->savingsGoal;
            $currentAmount = (float) $goal->deposits()->sum('amount');
            $goal->is_completed = $currentAmount >= $goal->target_amount;
            $goal->saveQuietly();
        });

        static::deleted(function (SavingsGoalDeposit $deposit) {
            $goal = $deposit->savingsGoal;
            $currentAmount = (float) $goal->deposits()->sum('amount');
            $goal->is_completed = $currentAmount >= $goal->target_amount;
            $goal->saveQuietly();

            // Remover transação associada ao depósito
            $user = $goal->user;
            $user->transactions()
                ->get()
                ->filter(function ($transaction) use ($deposit) {
                    $metadata = $transaction->metadata ?? [];
                    return isset($metadata['savings_goal_deposit_id']) && $metadata['savings_goal_deposit_id'] == $deposit->id;
                })
                ->each(function ($transaction) {
                    $transaction->delete();
                });
        });
    }
}
