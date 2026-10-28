<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgendaDigestPreference extends Model
{
    protected $fillable = [
        'user_id',
        'enabled',
        'send_time',
        'timezone',
        'last_sent_for',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'last_sent_for' => 'date:Y-m-d',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
