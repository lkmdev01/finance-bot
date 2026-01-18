<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionDuplicate extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'transaction_id',
        'duplicate_transaction_id',
        'similarity_score',
        'match_criteria',
        'is_resolved',
    ];

    protected function casts(): array
    {
        return [
            'similarity_score' => 'decimal:2',
            'match_criteria' => 'array',
            'is_resolved' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function duplicateTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'duplicate_transaction_id');
    }
}
