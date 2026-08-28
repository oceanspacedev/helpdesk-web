<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HelpdeskReporterBinding extends Model
{
    protected $fillable = [
        'client_key',
        'channel',
        'external_user_hash',
        'user_id',
        'verified_phone_hash',
        'verification_method',
        'verified_at',
        'last_seen_at',
        'revoked_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'verified_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }
}
