<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Budget extends Model
{
    /** @use HasFactory<\Database\Factories\BudgetFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'category_id',
        'amount',
        'period',
        'year',
        'month',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'year' => 'integer',
            'month' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function getSpentAttribute(): float
    {
        if (! $this->category) {
            return 0.0;
        }

        $query = $this->category->transactions()
            ->where('type', 'expense')
            ->where('user_id', $this->user_id);

        if ($this->period === 'monthly' && $this->month) {
            $query->whereYear('date', $this->year)
                ->whereMonth('date', $this->month);
        } else {
            $query->whereYear('date', $this->year);
        }

        return (float) $query->sum('amount');
    }

    public function getRemainingAttribute(): float
    {
        return $this->amount - $this->spent;
    }

    public function getPercentageUsedAttribute(): float
    {
        if ($this->amount == 0) {
            return 0;
        }
        return min(100, ($this->spent / $this->amount) * 100);
    }
}
