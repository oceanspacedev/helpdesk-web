<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Sistem eksternal yang dipasang ke helpdesk (lihat docs/integrations/README.md).
 *
 * @property int $id
 * @property string $name
 * @property string $base_url
 * @property null|string $token
 * @property null|string $webhook_secret
 * @property bool $is_active
 * @property int $timeout_seconds
 * @property null|Carbon $last_checked_at
 * @property null|bool $last_check_ok
 * @property null|string $last_check_message
 */
class Integration extends Model
{
    public const DEFAULT_TIMEOUT_SECONDS = 15;

    public const MAX_TIMEOUT_SECONDS = 25;

    protected $table = 'integrations';

    protected $fillable = [
        'name',
        'base_url',
        'token',
        'webhook_secret',
        'is_active',
        'timeout_seconds',
        'last_checked_at',
        'last_check_ok',
        'last_check_message',
    ];

    /**
     * Rahasia tidak pernah ikut ke array/JSON (termasuk pengisian form
     * Filament), sehingga nilainya tidak pernah ditampilkan ulang.
     */
    protected $hidden = [
        'token',
        'webhook_secret',
    ];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'is_active' => 'boolean',
            'timeout_seconds' => 'integer',
            'last_checked_at' => 'datetime',
            'last_check_ok' => 'boolean',
        ];
    }

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * Timeout HTTP efektif: dijaga di bawah max_execution_time PHP karena
     * pengiriman tiket berjalan sinkron di request pelapor.
     */
    public function timeoutSeconds(): int
    {
        $timeout = (int) ($this->timeout_seconds ?: self::DEFAULT_TIMEOUT_SECONDS);

        return max(1, min(self::MAX_TIMEOUT_SECONDS, $timeout));
    }

    public function webhookUrl(): string
    {
        return url('/api/integrations/'.$this->getKey().'/events');
    }

    public function isInUse(): bool
    {
        return $this->units()->withTrashed()->exists()
            || $this->tickets()->withTrashed()->exists();
    }
}
