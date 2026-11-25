<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class Unit.
 *
 * @property int $id
 * @property string $name
 * @property null|int $integration_id
 * @property null|Integration $integration
 * @property Collection|ProblemCategory[] $problem_categories
 * @property Collection|Ticket[] $tickets
 * @property Collection|User[] $users
 */
class Unit extends Model
{
    use SoftDeletes;

    public $timestamps = false;

    protected $table = 'units';

    protected $casts = [
        'integration_id' => 'int',
    ];

    protected $fillable = [
        'name',
        'integration_id',
    ];

    /**
     * Sistem eksternal pemilik form dan proses tiket divisi ini. Kosong
     * berarti seluruh data tiket divisi ada di helpdesk.
     */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function isExternal(): bool
    {
        return $this->integration_id !== null;
    }

    /**
     * Divisi yang datanya ada di helpdesk (tanpa integrasi).
     */
    public function scopeInternal(Builder $query): Builder
    {
        return $query->whereNull($query->qualifyColumn('integration_id'));
    }

    /**
     * Get all of the problemCategories for the Unit
     *
     * @return HasMany
     */
    public function problemCategories()
    {
        return $this->hasMany(ProblemCategory::class);
    }

    /**
     * Divisi yang bisa dituju laporan: hanya yang sudah punya kategori masalah.
     * Divisi lain (dari Talenta) tetap dipakai sebagai divisi asal pelapor.
     */
    public function scopeAcceptingTickets(Builder $query): Builder
    {
        return $query->whereHas('problemCategories');
    }

    /**
     * Get all of the tickets for the Unit
     *
     * @return HasMany
     */
    public function tickets()
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * Mendapatkan semua pengguna yang terkait dengan unit melalui user entities.
     *
     * @return MorphToMany
     */
    public function users()
    {
        return $this->morphToMany(User::class, 'entity', 'user_entities');
    }

    /**
     * Get all of the SLAs for the Unit
     *
     * @return HasMany
     */
    public function slas()
    {
        return $this->hasMany(UnitSla::class);
    }
}
