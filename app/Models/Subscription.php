<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'category_id',
        'bank_account_id',
        'credit_card_id',
        'name',
        'description',
        'amount',
        'billing_cycle',
        'due_day',
        'start_date',
        'last_paid_at',
        'next_due_date',
        'auto_record',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'due_day' => 'integer',
            'start_date' => 'date',
            'last_paid_at' => 'date',
            'next_due_date' => 'date',
            'auto_record' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Subscription $subscription) {
            if (! $subscription->next_due_date) {
                $subscription->next_due_date = $subscription->calculateNextDueDate();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function creditCard(): BelongsTo
    {
        return $this->belongsTo(CreditCard::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function calculateNextDueDate(?Carbon $reference = null): Carbon
    {
        $reference ??= $this->last_paid_at?->copy() ?? Carbon::parse($this->start_date);

        if ($this->billing_cycle === 'yearly') {
            $next = $reference->copy()->addYear();

            if ($this->due_day) {
                $next->day(min($this->due_day, $next->daysInMonth));
            }

            return $next->startOfDay();
        }

        $baseDate = $reference->copy()->startOfMonth();

        if ($this->last_paid_at || $reference->lt(now()->startOfMonth())) {
            $baseDate->addMonth();
        }

        $dueDay = $this->due_day ?: Carbon::parse($this->start_date)->day;
        $baseDate->day(min($dueDay, $baseDate->daysInMonth));

        if (! $this->last_paid_at && $baseDate->lt(Carbon::parse($this->start_date)->startOfDay())) {
            $baseDate = Carbon::parse($this->start_date)->copy()->startOfDay();
        }

        return $baseDate->startOfDay();
    }

    public function markAsPaid(?Carbon $paidAt = null): Transaction
    {
        $paidAt ??= now();

        $transaction = $this->transactions()->create([
            'user_id' => $this->user_id,
            'category_id' => $this->category_id,
            'bank_account_id' => $this->bank_account_id,
            'credit_card_id' => $this->credit_card_id,
            'type' => 'expense',
            'amount' => $this->amount,
            'description' => $this->description ?: "Assinatura: {$this->name}",
            'date' => $paidAt->toDateString(),
            'metadata' => [
                'source' => 'subscription',
                'subscription_name' => $this->name,
            ],
        ]);

        $this->update([
            'last_paid_at' => $paidAt->toDateString(),
            'next_due_date' => $this->calculateNextDueDate($paidAt)->toDateString(),
        ]);

        return $transaction;
    }

    public function getSourceLabelAttribute(): string
    {
        if ($this->creditCard) {
            return "Cartao: {$this->creditCard->name}";
        }

        if ($this->bankAccount) {
            return "Conta: {$this->bankAccount->name}";
        }

        return 'Sem fonte definida';
    }

    public function getIsDueAttribute(): bool
    {
        return $this->is_active && $this->next_due_date && $this->next_due_date->lte(now()->startOfDay());
    }
}
