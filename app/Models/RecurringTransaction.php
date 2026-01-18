<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecurringTransaction extends Model
{
    /** @use HasFactory<\Database\Factories\RecurringTransactionFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'category_id',
        'type',
        'amount',
        'description',
        'frequency',
        'start_date',
        'end_date',
        'last_processed_at',
        'is_active',
        'day_of_month',
        'day_of_week',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
            'last_processed_at' => 'datetime',
            'is_active' => 'boolean',
            'day_of_month' => 'integer',
            'day_of_week' => 'integer',
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

    public function shouldProcessToday(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $today = now();

        // Verificar se está dentro do período válido
        if ($today->lt($this->start_date)) {
            return false;
        }

        if ($this->end_date && $today->gt($this->end_date)) {
            return false;
        }

        // Verificar se já foi processado hoje
        if ($this->last_processed_at && $this->last_processed_at->isToday()) {
            return false;
        }

        return match ($this->frequency) {
            'daily' => true,
            'weekly' => $today->dayOfWeek === $this->day_of_week,
            'monthly' => $today->day === $this->day_of_month,
            'yearly' => $today->format('m-d') === $this->start_date->format('m-d'),
            default => false,
        };
    }

    /**
     * Obtém a próxima data de ocorrência
     */
    public function getNextOccurrenceDate(): ?\Carbon\Carbon
    {
        if (! $this->is_active) {
            return null;
        }

        $today = now();

        if ($today->lt($this->start_date)) {
            return $this->start_date->copy();
        }

        if ($this->end_date && $today->gt($this->end_date)) {
            return null;
        }

        return match ($this->frequency) {
            'daily' => $today->copy()->addDay(),
            'weekly' => $today->copy()->next($this->day_of_week),
            'monthly' => $today->copy()->day($this->day_of_month ?? $this->start_date->day)->addMonth(),
            'yearly' => $today->copy()->month($this->start_date->month)->day($this->start_date->day)->addYear(),
            default => null,
        };
    }
}
