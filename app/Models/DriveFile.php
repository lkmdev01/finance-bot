<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriveFile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'whatsapp_incoming_media_id',
        'source',
        'original_name',
        'mime_type',
        'size_bytes',
        'sha256',
        'drive_file_id',
        'drive_parent_id',
        'drive_path',
        'title',
        'description',
        'tags',
        'extracted_text',
        'metadata',
        'metadata_status',
        'metadata_error',
        'metadata_analyzed_at',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'metadata' => 'array',
            'metadata_analyzed_at' => 'datetime',
        ];
    }

    public function scopeMetadataFailed($query)
    {
        return $query->where('metadata_status', 'failed');
    }

    public function scopeMetadataPending($query)
    {
        return $query->where('metadata_status', 'pending');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function incomingMedia(): BelongsTo
    {
        return $this->belongsTo(WhatsAppIncomingMedia::class, 'whatsapp_incoming_media_id');
    }
}
