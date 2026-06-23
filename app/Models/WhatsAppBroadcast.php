<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppBroadcast extends Model
{
    use HasFactory;

    protected $fillable = [
        'admin_user_id',
        'user_id',
        'whats_app_contact_id',
        'recipient_name',
        'phone_number',
        'audience',
        'message',
        'status',
        'provider_status',
        'provider_response',
        'error_message',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'provider_response' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
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
