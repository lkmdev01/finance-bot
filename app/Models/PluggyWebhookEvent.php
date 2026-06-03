<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PluggyWebhookEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'event_name',
        'item_id',
        'client_user_id',
        'status',
        'error_message',
        'payload',
        'received_at',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
