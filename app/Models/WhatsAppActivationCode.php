<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class WhatsAppActivationCode extends Model
{
    protected $fillable = [
        'client_key',
        'code',
        'verified_phone_number',
        'verified_at',
        'expires_at',
        'consumed_at',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isVerified(): bool
    {
        return $this->verified_at instanceof Carbon;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
