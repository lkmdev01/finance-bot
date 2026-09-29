<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppMessageReceipt extends Model
{
    public const RECEIVED = 'received';

    public const PROCESSING = 'processing';

    public const COMPLETED = 'completed';

    public const NEEDS_REVIEW = 'needs_review';

    protected $fillable = ['event_key', 'user_id', 'status', 'review_reason', 'processing_started_at', 'completed_at'];

    protected function casts(): array
    {
        return [
            'processing_started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
