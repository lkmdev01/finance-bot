<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MascotAchievementUnlock extends Model
{
    use HasFactory;

    public $timestamps = true;

    protected $table = 'mascot_achievement_unlocks';

    protected $fillable = [
        'user_id',
        'achievement_key',
        'unlocked_at',
        'seen_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'unlocked_at' => 'datetime',
            'seen_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
