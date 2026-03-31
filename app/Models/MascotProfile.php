<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MascotProfile extends Model
{
    use HasFactory;

    protected $table = 'mascot_profiles';

    protected $fillable = [
        'user_id',
        'score',
        'xp',
        'level',
        'current_streak',
        'best_streak',
        'badges_unlocked',
        'mood',
        'last_activity_date',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'xp' => 'integer',
            'level' => 'integer',
            'current_streak' => 'integer',
            'best_streak' => 'integer',
            'badges_unlocked' => 'integer',
            'last_activity_date' => 'date',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
