<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SavingsGoal extends Model
{
    /** @use HasFactory<\Database\Factories\SavingsGoalFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'target_amount',
        'target_date',
        'is_completed',
    ];

    protected function casts(): array
    {
        return [
            'target_amount' => 'decimal:2',
            'target_date' => 'date',
            'is_completed' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function deposits(): HasMany
    {
        return $this->hasMany(SavingsGoalDeposit::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(SavingsGoalAlert::class);
    }

    public function getCurrentAmountAttribute(): float
    {
        return (float) $this->deposits()->sum('amount');
    }

    public function getProgressPercentageAttribute(): float
    {
        if ($this->target_amount == 0) {
            return 0;
        }
        $current = $this->current_amount;
        return min(100, ($current / $this->target_amount) * 100);
    }

    public function getRemainingAmountAttribute(): float
    {
        return max(0, $this->target_amount - $this->current_amount);
    }

}
