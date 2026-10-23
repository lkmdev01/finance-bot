<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingWebhookEvent extends Model
{
    protected $fillable = ['event_hash', 'meeting_id', 'event_type', 'payload', 'received_at'];

    protected function casts(): array
    {
        return ['received_at' => 'datetime', 'payload' => 'encrypted:array'];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }
}
