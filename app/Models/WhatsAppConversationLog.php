<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppConversationLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'whats_app_contact_id',
        'phone_number',
        'message',
        'classification',
        'action',
        'handler',
        'used_ai',
        'status',
        'reply',
        'error_type',
        'error_message',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'used_ai' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(WhatsAppContact::class, 'whats_app_contact_id');
    }
}
