<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppOutboundMessage extends Model
{
    protected $fillable = ['user_id', 'recipient_jid', 'body', 'status', 'attempts', 'last_http_status', 'delivered_at'];

    protected function casts(): array
    {
        return [
            'recipient_jid' => 'encrypted',
            'body' => 'encrypted',
            'delivered_at' => 'datetime',
        ];
    }
}
