<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppIncomingMedia extends Model
{
    use HasFactory;

    protected $table = 'whatsapp_incoming_media';

    protected $fillable = [
        'user_id',
        'phone_number',
        'kind',
        'storage_disk',
        'storage_path',
        'original_name',
        'mime_type',
        'size_bytes',
        'sha256',
        'received_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

