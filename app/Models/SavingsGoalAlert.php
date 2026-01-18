<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavingsGoalAlert extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'savings_goal_id',
        'type',
        'threshold_percentage',
        'days_before_deadline',
        'is_active',
        'last_triggered_at',
    ];

    protected function casts(): array
    {
        return [
            'threshold_percentage' => 'decimal:2',
            'days_before_deadline' => 'integer',
            'is_active' => 'boolean',
            'last_triggered_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function savingsGoal(): BelongsTo
    {
        return $this->belongsTo(SavingsGoal::class);
    }

    public function shouldTrigger(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $goal = $this->savingsGoal;

        if (! $goal) {
            return false;
        }

        return match ($this->type) {
            'milestone' => $this->checkMilestone($goal),
            'deadline' => $this->checkDeadline($goal),
            'low_progress' => $this->checkLowProgress($goal),
            default => false,
        };
    }

    protected function checkMilestone(SavingsGoal $goal): bool
    {
        if (! $this->threshold_percentage) {
            return false;
        }

        $currentPercentage = $goal->progress_percentage;

        return $currentPercentage >= $this->threshold_percentage
            && ($this->last_triggered_at === null || $this->last_triggered_at->lt(now()->subDay()));
    }

    protected function checkDeadline(SavingsGoal $goal): bool
    {
        if (! $this->days_before_deadline || ! $goal->target_date) {
            return false;
        }

        $daysUntilDeadline = now()->diffInDays($goal->target_date, false);

        return $daysUntilDeadline <= $this->days_before_deadline
            && $daysUntilDeadline >= 0
            && ($this->last_triggered_at === null || $this->last_triggered_at->lt(now()->subDay()));
    }

    protected function checkLowProgress(SavingsGoal $goal): bool
    {
        if (! $goal->target_date) {
            return false;
        }

        $daysUntilDeadline = now()->diffInDays($goal->target_date, false);
        $daysElapsed = $goal->created_at->diffInDays(now());
        $totalDays = $goal->created_at->diffInDays($goal->target_date);

        if ($totalDays <= 0 || $daysUntilDeadline < 0) {
            return false;
        }

        $expectedProgress = ($daysElapsed / $totalDays) * 100;
        $actualProgress = $goal->progress_percentage;

        return $actualProgress < ($expectedProgress * 0.5) // Menos de 50% do esperado
            && ($this->last_triggered_at === null || $this->last_triggered_at->lt(now()->subDay()));
    }
}
