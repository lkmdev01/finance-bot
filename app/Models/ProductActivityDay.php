<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductActivityDay extends Model
{
    protected $fillable = ['user_id', 'activity_date', 'source', 'interaction_count'];

    protected function casts(): array
    {
        return [
            'activity_date' => 'date',
            'interaction_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
