<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgendaUserPreference extends Model
{
    protected $fillable = [
        'user_id',
        'default_event_duration_minutes',
        'default_reminder_minutes',
        'workday_start',
        'workday_end',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
