<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AbacatePayWebhookEvent extends Model
{
    protected $fillable = [
        'external_id',
        'event_name',
        'api_version',
        'dev_mode',
        'status',
        'payload',
        'received_at',
        'processed_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'api_version' => 'integer',
            'dev_mode' => 'boolean',
            'payload' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
