<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResearchQuery extends Model
{
    protected $fillable = [
        'user_id', 'whatsapp_contact_id', 'query', 'search_query', 'answer', 'sources', 'status',
        'provider', 'failure_code', 'completed_at', 'lease_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'query' => 'encrypted',
            'search_query' => 'encrypted',
            'answer' => 'encrypted',
            'sources' => 'array',
            'completed_at' => 'datetime',
            'lease_expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(WhatsAppContact::class, 'whatsapp_contact_id');
    }
}
