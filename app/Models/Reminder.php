<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class Reminder extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'message',
        'frequency',
        'timezone',
        'next_trigger_at',
        'day_of_week',
        'day_of_month',
        'month_of_year',
        'trigger_time',
        'last_sent_at',
        'is_active',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'next_trigger_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'day_of_week' => 'integer',
            'day_of_month' => 'integer',
            'month_of_year' => 'integer',
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shouldDispatch(?Carbon $reference = null): bool
    {
        $reference ??= now($this->timezone ?: config('app.timezone'));

        return $this->is_active
            && $this->next_trigger_at !== null
            && $this->next_trigger_at->lessThanOrEqualTo($reference);
    }

    public function advanceAfterDispatch(?Carbon $sentAt = null): void
    {
        $sentAt ??= now($this->timezone ?: config('app.timezone'));

        $this->last_sent_at = $sentAt;

        if ($this->frequency === 'once') {
            $this->is_active = false;
            $this->next_trigger_at = null;
            $this->save();

            return;
        }

        $time = $this->trigger_time ?? '09:00:00';

        if ($this->frequency === 'daily') {
            $next = $sentAt->copy()->addDay();
            $this->applyTime($next, $time);
            $this->next_trigger_at = $next;
            $this->save();

            return;
        }

        if ($this->frequency === 'weekly' && $this->day_of_week !== null) {
            $next = $sentAt->copy()->addDay();
            $this->applyTime($next, $time);

            while ($next->dayOfWeek !== $this->day_of_week) {
                $next->addDay();
            }

            $this->next_trigger_at = $next;
            $this->save();

            return;
        }

        if ($this->frequency === 'monthly' && $this->day_of_month !== null) {
            $next = $sentAt->copy()->addMonthNoOverflow()->startOfMonth();
            $next->day(min($this->day_of_month, $next->daysInMonth));
            $this->applyTime($next, $time);
            $this->next_trigger_at = $next;
            $this->save();

            return;
        }

        if ($this->frequency === 'yearly' && $this->day_of_month !== null && $this->month_of_year !== null) {
            $next = Carbon::create($sentAt->year + 1, $this->month_of_year, 1, 0, 0, 0, $this->timezone ?: config('app.timezone'));
            $next->day(min($this->day_of_month, $next->daysInMonth));
            $this->applyTime($next, $time);
            $this->next_trigger_at = $next;
            $this->save();

            return;
        }

        $this->save();
    }

    private function applyTime(Carbon $date, string $time): void
    {
        [$hour, $minute, $second] = array_map('intval', explode(':', $time));
        $date->setTime($hour, $minute, $second);
    }
}
