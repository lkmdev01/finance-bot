<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsAppContact extends Model
{
    /** @use HasFactory<\Database\Factories\WhatsAppContactFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'phone_number',
        'name',
        'context',
        'conversation_state',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'encrypted:array',
            'conversation_state' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
