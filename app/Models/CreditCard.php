<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreditCard extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'issuer',
        'brand',
        'open_finance_connection_id',
        'open_finance_provider',
        'open_finance_account_id',
        'last_four',
        'credit_limit',
        'opening_balance',
        'open_finance_balance',
        'open_finance_available_limit',
        'open_finance_synced_at',
        'closing_day',
        'due_day',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:2',
            'opening_balance' => 'decimal:2',
            'open_finance_balance' => 'decimal:2',
            'open_finance_available_limit' => 'decimal:2',
            'open_finance_synced_at' => 'datetime',
            'closing_day' => 'integer',
            'due_day' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function openFinanceConnection(): BelongsTo
    {
        return $this->belongsTo(OpenFinanceConnection::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function recurringTransactions(): HasMany
    {
        return $this->hasMany(RecurringTransaction::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function getCurrentBalanceAttribute(): float
    {
        $balance = (float) $this->opening_balance;

        foreach ($this->transactions as $transaction) {
            $balance += $transaction->type === 'expense'
                ? (float) $transaction->amount
                : -1 * (float) $transaction->amount;
        }

        return round($balance, 2);
    }

    public function getAvailableLimitAttribute(): float
    {
        return round((float) $this->credit_limit - $this->current_balance, 2);
    }
}
