<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiUserTokenUsage extends Model
{
    protected $fillable = ['user_id', 'period', 'tokens_used'];

    protected function casts(): array
    {
        return ['tokens_used' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
