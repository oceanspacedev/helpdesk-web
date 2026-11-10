<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class ProblemCategory.
 *
 * @property int $id
 * @property int $unit_id
 * @property string $name
 * @property Unit $unit
 * @property Collection|User[] $defaultResponsibles
 * @property Collection|Ticket[] $tickets
 */
class ProblemCategory extends Model
{
    use SoftDeletes;

    public $timestamps = false;

    protected $table = 'problem_categories';

    protected $casts = [
        'unit_id' => 'int',
    ];

    protected $fillable = [
        'unit_id',
        'name',
    ];

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * PIC default kategori. Tiket baru kategori ini langsung ditugaskan ke
     * salah satu dari mereka (yang tiket aktifnya paling sedikit) dan
     * notifikasinya hanya dikirim ke para PIC, bukan ke seluruh divisi.
     */
    public function defaultResponsibles(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'problem_category_responsibles')
            ->withTimestamps()
            ->orderBy('users.name');
    }

    /**
     * PIC default yang masih boleh menangani tiket divisi tujuan. PIC yang
     * sudah nonaktif atau pindah divisi diabaikan agar tiket kembali ke
     * antrean divisi, bukan nyangkut di orang yang tidak bisa memprosesnya.
     *
     * @return Collection<int, User>
     */
    public function eligibleDefaultResponsibles(?int $unitId = null): Collection
    {
        $unitId ??= (int) $this->unit_id;

        $responsibles = $this->relationLoaded('defaultResponsibles')
            ? $this->defaultResponsibles
            : $this->defaultResponsibles()->get();

        return $responsibles
            ->filter(fn (User $responsible): bool => $responsible->isActiveTicketProcessorForUnit($unitId))
            ->values();
    }

    /**
     * PIC yang menerima tiket baru: yang tiket aktifnya (belum selesai dan
     * belum dibatalkan) paling sedikit. Seri dipecah berdasarkan nama lalu
     * id agar hasilnya selalu sama.
     */
    public function pickDefaultResponsible(?int $unitId = null): ?User
    {
        $candidates = $this->eligibleDefaultResponsibles($unitId);

        if ($candidates->count() <= 1) {
            return $candidates->first();
        }

        $activeTickets = Ticket::query()
            ->whereIn('tickets.responsible_id', $candidates->modelKeys())
            ->whereNotIn('tickets.ticket_statuses_id', [TicketStatus::CANCEL, TicketStatus::CLOSED])
            ->groupBy('tickets.responsible_id')
            ->selectRaw('tickets.responsible_id, count(*) as total')
            ->pluck('total', 'responsible_id');

        return $candidates
            ->sort(fn (User $a, User $b): int => ((int) ($activeTickets[$a->id] ?? 0) <=> (int) ($activeTickets[$b->id] ?? 0))
                ?: strcasecmp((string) $a->name, (string) $b->name)
                ?: ($a->id <=> $b->id))
            ->first();
    }

    /**
     * Batasi kandidat PIC default ke anggota aktif divisi tersebut ditambah
     * admin global, sama seperti pilihan penanggung jawab di tiket. Tanpa
     * divisi tidak ada kandidat sama sekali.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public static function constrainDefaultResponsibleCandidates(Builder $query, ?int $unitId): Builder
    {
        if (! $unitId) {
            return $query->whereRaw('0 = 1');
        }

        return $query
            ->ticketProcessorsForUnit($unitId, includeGlobal: true)
            ->orderBy('users.name');
    }

    /**
     * @return array<int, string>
     */
    public static function defaultResponsibleOptions(?int $unitId): array
    {
        return static::constrainDefaultResponsibleCandidates(User::query(), $unitId)
            ->pluck('users.name', 'users.id')
            ->all();
    }

    public function tickets()
    {
        return $this->hasMany(Ticket::class);
    }
}
