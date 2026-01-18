<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Webhook extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'url',
        'secret',
        'events',
        'is_active',
        'success_count',
        'failure_count',
        'last_triggered_at',
    ];

    protected function casts(): array
    {
        return [
            'events' => 'array',
            'is_active' => 'boolean',
            'success_count' => 'integer',
            'failure_count' => 'integer',
            'last_triggered_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shouldTrigger(string $event): bool
    {
        return $this->is_active && in_array($event, $this->events ?? []);
    }

    public function recordSuccess(): void
    {
        $this->increment('success_count');
        $this->update(['last_triggered_at' => now()]);
    }

    public function recordFailure(): void
    {
        $this->increment('failure_count');
    }
}
