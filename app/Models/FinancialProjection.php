<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialProjection extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'projection_date',
        'projected_balance',
        'projected_income',
        'projected_expenses',
        'assumptions',
    ];

    protected function casts(): array
    {
        return [
            'projection_date' => 'date',
            'projected_balance' => 'decimal:2',
            'projected_income' => 'decimal:2',
            'projected_expenses' => 'decimal:2',
            'assumptions' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
