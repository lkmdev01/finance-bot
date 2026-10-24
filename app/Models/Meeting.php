<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Meeting extends Model
{
    protected $fillable = [
        'user_id', 'whats_app_contact_id', 'platform', 'native_meeting_id_hash',
        'native_meeting_id', 'meeting_url', 'vexa_meeting_id', 'status',
        'vexa_meeting_id_hash', 'participant_notice_attested', 'participant_notice_attested_at',
        'transcript', 'summary', 'failure_code', 'started_at', 'completed_at',
        'reported_at', 'expires_at', 'transcript_segments', 'transcript_expires_at',
        'active_since', 'max_duration_warning_sent_at', 'stop_requested_at', 'stop_reason',
    ];

    protected function casts(): array
    {
        return [
            'native_meeting_id' => 'encrypted',
            'meeting_url' => 'encrypted',
            'vexa_meeting_id' => 'encrypted',
            'transcript' => 'encrypted',
            'transcript_segments' => 'encrypted:array',
            'summary' => 'encrypted',
            'started_at' => 'datetime',
            'active_since' => 'datetime',
            'max_duration_warning_sent_at' => 'datetime',
            'stop_requested_at' => 'datetime',
            'completed_at' => 'datetime',
            'reported_at' => 'datetime',
            'expires_at' => 'datetime',
            'transcript_expires_at' => 'datetime',
            'participant_notice_attested' => 'boolean',
            'participant_notice_attested_at' => 'datetime',
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

    public function transcriptionTask(): HasOne
    {
        return $this->hasOne(MeetingTranscriptionTask::class);
    }
}
