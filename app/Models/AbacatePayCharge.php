<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AbacatePayCharge extends Model
{
    protected $fillable = [
        'user_id',
        'external_id',
        'gateway_charge_id',
        'charge_type',
        'method',
        'status',
        'amount',
        'paid_amount',
        'payment_url',
        'br_code',
        'br_code_base64',
        'receipt_url',
        'expires_at',
        'dev_mode',
        'customer_name',
        'customer_email',
        'customer_tax_id',
        'payload',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'paid_amount' => 'integer',
            'expires_at' => 'datetime',
            'dev_mode' => 'boolean',
            'payload' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
