<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoogleCalendarConnection extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'google_email',
        'refresh_token',
        'scopes',
        'calendar_id',
        'connected_at',
        'revoked_at',
    ];

    protected $hidden = ['refresh_token'];

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
