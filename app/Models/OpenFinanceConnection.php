<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OpenFinanceConnection extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'provider',
        'item_id',
        'connector_id',
        'connector_name',
        'status',
        'execution_status',
        'last_sync_summary',
        'sync_error',
        'connected_at',
        'last_synced_at',
        'disconnected_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'connector_id' => 'integer',
            'last_sync_summary' => 'array',
            'metadata' => 'array',
            'connected_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'disconnected_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(BankAccount::class);
    }

    public function creditCards(): HasMany
    {
        return $this->hasMany(CreditCard::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
