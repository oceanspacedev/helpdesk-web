<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class ProblemCategory.
 *
 * @property int $id
 * @property int $unit_id
 * @property string $name
 * @property null|int $default_responsible_id
 * @property Unit $unit
 * @property null|User $defaultResponsible
 * @property Collection|Ticket[] $tickets
 */
class ProblemCategory extends Model
{
    use SoftDeletes;

    public $timestamps = false;

    protected $table = 'problem_categories';

    protected $casts = [
        'unit_id' => 'int',
        'default_responsible_id' => 'int',
    ];

    protected $fillable = [
        'unit_id',
        'name',
        'default_responsible_id',
    ];

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * PIC default kategori. Tiket baru kategori ini langsung ditugaskan ke
     * orang ini dan notifikasinya tidak disebar ke seluruh anggota divisi.
     *
     * @return BelongsTo
     */
    public function defaultResponsible()
    {
        return $this->belongsTo(User::class, 'default_responsible_id');
    }

    /**
     * PIC default yang masih boleh menangani tiket divisi tujuan. PIC yang
     * sudah nonaktif atau pindah divisi diabaikan agar tiket kembali ke
     * antrean divisi, bukan nyangkut di orang yang tidak bisa memprosesnya.
     */
    public function eligibleDefaultResponsible(?int $unitId = null): ?User
    {
        if (! $this->default_responsible_id) {
            return null;
        }

        $responsible = $this->relationLoaded('defaultResponsible')
            ? $this->defaultResponsible
            : $this->defaultResponsible()->first();

        return $responsible?->isActiveTicketProcessorForUnit($unitId ?? (int) $this->unit_id)
            ? $responsible
            : null;
    }

    /**
     * Pilihan PIC default untuk sebuah divisi: anggota aktif divisi tersebut
     * ditambah admin global, sama seperti pilihan penanggung jawab di tiket.
     *
     * @return array<int, string>
     */
    public static function defaultResponsibleOptions(?int $unitId): array
    {
        if (! $unitId) {
            return [];
        }

        return User::query()
            ->ticketProcessorsForUnit($unitId, includeGlobal: true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function tickets()
    {
        return $this->hasMany(Ticket::class);
    }
}
