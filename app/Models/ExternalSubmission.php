<?php

namespace App\Models;

use App\Support\SchemaPresence;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Jejak satu pengajuan pass-through ke sistem eksternal: kode referensi,
 * judul, status terakhir, dan tautan lacak. Isi pengajuannya hanya ada di
 * sistem tujuan (kontrak integrasi §5).
 *
 * @property int $id
 * @property int $integration_id
 * @property null|int $user_id
 * @property null|int $unit_id
 * @property null|string $category_name
 * @property string $external_ref
 * @property string $title
 * @property null|string $status_code
 * @property null|string $status_label
 * @property null|string $track_url
 * @property null|Carbon $status_checked_at
 */
class ExternalSubmission extends Model
{
    protected $fillable = [
        'integration_id',
        'user_id',
        'unit_id',
        'category_name',
        'external_ref',
        'title',
        'status_code',
        'status_label',
        'track_url',
        'status_checked_at',
    ];

    protected function casts(): array
    {
        return ['status_checked_at' => 'datetime'];
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class)->withTrashed();
    }

    /** Pengajuan milik pengguna; admin global melihat semuanya. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->hasGlobalTicketAccess() ? $query : $query->where('user_id', $user->getKey());
    }

    /**
     * Catat (atau perbarui, bila kirim ulang idempoten) hasil kiriman
     * ExternalTicketService::submit(). Tidak pernah menggagalkan pengajuan:
     * pengajuannya sudah diterima sistem tujuan.
     *
     * @param  array{ref: string, title: string, status_code: string, status_label: string, track_url: ?string}  $result
     */
    public static function record(Integration $integration, User $reporter, ProblemCategory $category, array $result): void
    {
        try {
            // Deploy bisa mendahului migrasi; jejak ini pelengkap, bukan syarat.
            if (! SchemaPresence::has('external_submissions')) {
                return;
            }

            self::query()->updateOrCreate(
                ['integration_id' => $integration->getKey(), 'external_ref' => Str::limit($result['ref'], 191, '')],
                [
                    'user_id' => $reporter->getKey(),
                    'unit_id' => $category->unit_id,
                    'category_name' => $category->name,
                    'title' => Str::limit($result['title'], 250, ''),
                    'status_code' => Str::limit($result['status_code'], 32, ''),
                    'status_label' => Str::limit($result['status_label'], 255, ''),
                    'track_url' => $result['track_url'] !== null && strlen($result['track_url']) <= 1000 ? $result['track_url'] : null,
                    'status_checked_at' => now(),
                ],
            );
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
