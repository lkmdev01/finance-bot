<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AbacatePaySubscription extends Model
{
    protected $fillable = [
        'user_id',
        'plan_code',
        'external_id',
        'gateway_subscription_id',
        'gateway_customer_id',
        'gateway_checkout_id',
        'checkout_url',
        'gateway_payment_id',
        'customer_name',
        'customer_email',
        'customer_tax_id',
        'amount',
        'currency',
        'method',
        'frequency',
        'status',
        'dev_mode',
        'starts_at',
        'renewed_at',
        'cancelled_at',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'dev_mode' => 'boolean',
            'starts_at' => 'datetime',
            'renewed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
