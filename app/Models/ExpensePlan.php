<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpensePlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'planned_amount',
        'spent_amount',
        'start_date',
        'end_date',
        'categories',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'planned_amount' => 'decimal:2',
            'spent_amount' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
            'categories' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getRemainingAmountAttribute(): float
    {
        return max(0, $this->planned_amount - $this->spent_amount);
    }

    public function getProgressPercentageAttribute(): float
    {
        if ($this->planned_amount == 0) {
            return 0;
        }

        return min(100, ($this->spent_amount / $this->planned_amount) * 100);
    }

    public function getIsExceededAttribute(): bool
    {
        return $this->spent_amount > $this->planned_amount;
    }

    public function updateSpentAmount(): void
    {
        $categories = $this->categories ?? [];
        
        $spent = $this->user->transactions()
            ->where('type', 'expense')
            ->whereBetween('date', [$this->start_date, $this->end_date])
            ->when(count($categories) > 0, function ($query) use ($categories) {
                $query->whereIn('category_id', $categories);
            })
            ->sum('amount');

        $this->update(['spent_amount' => (float) $spent]);
    }
}
