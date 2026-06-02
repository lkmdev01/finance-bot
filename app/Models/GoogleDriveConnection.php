<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoogleDriveConnection extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'refresh_token',
        'scopes',
        'root_folder_id',
        'connected_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'connected_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

