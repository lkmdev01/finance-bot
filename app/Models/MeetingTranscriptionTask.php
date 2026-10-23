<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingTranscriptionTask extends Model
{
    protected $fillable = [
        'meeting_id', 'status', 'attempts', 'max_attempts', 'available_at',
        'lease_token_hash', 'lease_expires_at', 'heartbeat_at', 'recording_id',
        'media_file_id', 'processing_metadata', 'last_error_code', 'received_at',
    ];

    protected function casts(): array
    {
        return [
            'available_at' => 'datetime',
            'lease_expires_at' => 'datetime',
            'heartbeat_at' => 'datetime',
            'received_at' => 'datetime',
            'recording_id' => 'encrypted',
            'media_file_id' => 'encrypted',
            'processing_metadata' => 'encrypted:array',
        ];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }
}
