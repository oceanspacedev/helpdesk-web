<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use App\Notifications\NewTicketNotification;
use App\Notifications\TicketStatusChangedNotification;
use App\Notifications\TicketSubmittedNotification;
use App\Services\Integrations\HelpdeskOperationalClassifier;
use App\Support\HelpdeskNotifier;
use App\Support\SchemaPresence;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

/**
 * Class Ticket.
 *
 * @property int $id
 * @property int $priority_id
 * @property int $unit_id
 * @property int $owner_id
 * @property null|int $reporter_unit_id
 * @property null|string $reporter_job_title
 * @property null|int $reporter_business_entity_id
 * @property int $problem_category_id
 * @property string $title
 * @property string $description
 * @property int $ticket_statuses_id
 * @property null|int $responsible_id
 * @property null|Carbon $created_at
 * @property null|Carbon $updated_at
 * @property null|Carbon $approved_at
 * @property null|Carbon $solved_at
 * @property null|string $deleted_at
 * @property Priority $priority
 * @property Unit $unit
 * @property null|Unit $reporterUnit
 * @property null|BusinessEntity $businessEntity
 * @property null|BusinessEntity $reporterBusinessEntity
 * @property null|User $user
 * @property ProblemCategory $problem_category
 * @property TicketStatus $ticket_status
 * @property Collection|Comment[] $comments
 */
class Ticket extends Model
{
    use SoftDeletes;

    protected $table = 'tickets';

    protected $casts = [
        'priority_id' => 'int',
        'unit_id' => 'int',
        'owner_id' => 'int',
        'reporter_unit_id' => 'int',
        'reporter_business_entity_id' => 'int',
        'problem_category_id' => 'int',
        'ticket_statuses_id' => 'int',
        'responsible_id' => 'int',
        'business_entities_id' => 'int',
        'supporting_attachments' => 'array',
        'approved_at' => 'datetime',
        'solved_at' => 'datetime',
        'sla_due_at' => 'datetime',
        'is_sla_met' => 'boolean',
        'sla_warning_sent_at' => 'datetime',
    ];

    protected $fillable = [
        'priority_id',
        'unit_id',
        'reporter_unit_id',
        'reporter_job_title',
        'reporter_business_entity_id',
        'owner_id',
        'problem_category_id',
        'title',
        'description',
        'supporting_attachments',
        'ticket_statuses_id',
        'responsible_id',
        'business_entities_id',
        'approved_at',
        'solved_at',
        'sla_due_at',
        'is_sla_met',
        'sla_warning_sent_at',
    ];

    // Preventif error in migration
    public static $isSeeding = false;

    /**
     * Get the priority that owns the Ticket.
     *
     * @return BelongsTo
     */
    public function priority()
    {
        return $this->belongsTo(Priority::class);
    }

    /**
     * Get the unit that owns the Ticket.
     *
     * @return BelongsTo
     */
    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * Divisi asal pelapor saat tiket dibuat (bagian "Dari").
     *
     * @return BelongsTo
     */
    public function reporterUnit()
    {
        return $this->belongsTo(Unit::class, 'reporter_unit_id');
    }

    /**
     * Get the owner that owns the Ticket.
     *
     * @return BelongsTo
     */
    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Get the responsible that owns the Ticket.
     *
     * @return BelongsTo
     */
    public function responsible()
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    public function eligibleResponsible(): ?User
    {
        if (! $this->responsible_id) {
            return null;
        }

        $responsible = $this->relationLoaded('responsible')
            ? $this->responsible
            : $this->responsible()->first();

        return $responsible?->isActiveTicketProcessorForUnit((int) $this->unit_id)
            ? $responsible
            : null;
    }

    /**
     * Get the problemCategory that owns the Ticket.
     *
     * @return BelongsTo
     */
    public function problemCategory()
    {
        return $this->belongsTo(ProblemCategory::class);
    }

    /**
     * Get the ticketStatus that owns the Ticket.
     *
     * @return BelongsTo
     */
    public function ticketStatus()
    {
        return $this->belongsTo(TicketStatus::class, 'ticket_statuses_id');
    }

    /**
     * Get all of the comments for the Ticket.
     *
     * @return HasMany
     */
    public function comments()
    {
        return $this->hasMany(Comment::class, 'tiket_id');
    }

    /**
     * Get the ticketHistories that owns the Ticket.
     *
     * @return BelongsTo
     */
    public function ticketHistories()
    {
        return $this->hasMany(TicketHistory::class, 'ticket_id');
    }

    /**
     * Badan usaha yang dilaporkan. Bisa berbeda dari badan usaha kontrak
     * pelapor karena karyawan holding menangani banyak badan usaha.
     *
     * @return BelongsTo
     */
    public function businessEntity()
    {
        return $this->belongsTo(BusinessEntity::class, 'business_entities_id');
    }

    /**
     * Badan usaha kontrak pelapor saat tiket dibuat (bagian "Dari").
     *
     * @return BelongsTo
     */
    public function reporterBusinessEntity()
    {
        return $this->belongsTo(BusinessEntity::class, 'reporter_business_entity_id');
    }

    /**
     * Tiket yang boleh dilihat user: tiket yang ia kirim atau tiket masuk ke
     * unit yang ia layani. Admin global tetap dapat melihat seluruh tiket.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasGlobalTicketAccess()) {
            return $query;
        }

        $unitIds = $user->canProcessTickets() ? $user->assignedUnitIds() : [];

        return $query->where(function (Builder $query) use ($user, $unitIds): void {
            $query->where('tickets.owner_id', $user->getKey());

            if ($unitIds !== []) {
                $query->orWhereIn('tickets.unit_id', $unitIds);
            }
        });
    }

    /**
     * Kotak masuk adalah tiket yang ditujukan kepada unit yang dilayani user.
     */
    public function scopeIncomingFor(Builder $query, User $user): Builder
    {
        if ($user->hasGlobalTicketAccess()) {
            return $query;
        }

        $unitIds = $user->canProcessTickets() ? $user->assignedUnitIds() : [];

        return $unitIds === []
            ? $query->whereRaw('1 = 0')
            : $query->whereIn('tickets.unit_id', $unitIds);
    }

    /**
     * Kotak keluar selalu bersifat personal berdasarkan pembuat tiket.
     */
    public function scopeOutgoingFor(Builder $query, User $user): Builder
    {
        return $query->where('tickets.owner_id', $user->getKey());
    }

    protected static function boot()
    {
        parent::boot();

        // Seperti kop surat: bagian "Dari" diambil dari profil pelapor saat
        // tiket dibuat dan tidak ikut berubah bila pelapor pindah divisi.
        static::creating(function (self $ticket): void {
            $owner = $ticket->owner_id ? User::query()->find($ticket->owner_id) : null;
            if (! $owner) {
                return;
            }

            if ($ticket->reporter_unit_id === null && $owner->unit_id) {
                $ticket->reporter_unit_id = $owner->unit_id;
            }
            if ($ticket->reporter_job_title === null && filled($owner->job_title)) {
                $ticket->reporter_job_title = $owner->job_title;
            }
            if ($ticket->reporter_business_entity_id === null && $owner->business_entity_id) {
                $ticket->reporter_business_entity_id = $owner->business_entity_id;
            }
            // Badan usaha yang dilaporkan dipilih pelapor; badan usaha kontrak
            // hanya menjadi isian awal bila tidak ada yang dipilih.
            if ($ticket->business_entities_id === null && $owner->business_entity_id) {
                $ticket->business_entities_id = $owner->business_entity_id;
            }
        });

        // Event listener untuk event 'saving'
        static::saving(function ($ticket) {
            // PIC default kategori (diatur admin) menang: tiket jatuh ke PIC yang
            // tiket aktifnya paling sedikit. Bila kosong, pakai pemetaan lama
            // dokumen BUSDEV ke akun OD. Kategori tanpa PIC tetap masuk antrean
            // divisi dan dinotifikasi ke semua anggotanya.
            if (! self::$isSeeding && $ticket->isDirty('problem_category_id')) {
                try {
                    $ticket->responsible_id = self::defaultResponsibleIdForCategory($ticket);
                } catch (\Throwable $exception) {
                    report($exception);
                }
            }

            if (! self::$isSeeding
                && $ticket->responsible_id
                && ($ticket->isDirty('responsible_id') || $ticket->isDirty('unit_id'))) {
                $responsible = User::find($ticket->responsible_id);

                if (! $responsible?->isActiveTicketProcessorForUnit((int) $ticket->unit_id)) {
                    $ticket->responsible_id = null;
                }
            }

            if ($ticket->exists && $ticket->isDirty('ticket_statuses_id')) {
                // Set approved_at jika status bukan 1 dan belum di-approve
                if ($ticket->ticket_statuses_id != 1 && is_null($ticket->approved_at)) {
                    $ticket->approved_at = Carbon::now();
                }

                // Set solved_at jika status adalah 4. Notifikasi dikirim pada
                // event updated agar perubahan database sudah berhasil.
                if ($ticket->ticket_statuses_id == 4) {
                    if (is_null($ticket->solved_at)) {
                        $ticket->solved_at = Carbon::now();
                    }

                    if ($ticket->sla_due_at) {
                        $ticket->is_sla_met = Carbon::parse($ticket->solved_at)->lte($ticket->sla_due_at);
                    }
                } else {
                    // Reset solved_at dan is_sla_met jika dikembalikan dari Closed (4) ke status lain
                    if ($ticket->getOriginal('ticket_statuses_id') == 4) {
                        $ticket->solved_at = null;
                        $ticket->is_sla_met = null;
                    }
                }
            }

            // Hitung SLA Due At jika tiket sudah di-approve dan unit tersebut memiliki aturan UnitSla
            if ($ticket->approved_at && ($ticket->isDirty('approved_at') || $ticket->isDirty('priority_id') || $ticket->isDirty('unit_id'))) {
                if (SchemaPresence::has('unit_slas')) {
                    $sla = UnitSla::where('unit_id', $ticket->unit_id)
                        ->where('priority_id', $ticket->priority_id)
                        ->first();
                    if ($sla) {
                        $ticket->sla_due_at = Carbon::parse($ticket->approved_at)->addHours($sla->target_hours);
                    } else {
                        $ticket->sla_due_at = null;
                        $ticket->is_sla_met = null;
                    }
                }
            }
        });

        // Event listener untuk event 'created'
        static::created(function ($ticket) {
            if (self::$isSeeding) {
                return;
            }

            try {
                TicketHistory::create([
                    'ticket_id' => $ticket->id,
                    'ticket_statuses_id' => $ticket->ticket_statuses_id,
                    'user_id' => Auth::id(),
                    'created_at' => now(),
                ]);
            } catch (\Throwable $exception) {
                report($exception);
            }

            $staffIds = [];

            foreach ($ticket->newTicketRecipients() as $receiver) {
                HelpdeskNotifier::send($receiver, new NewTicketNotification($ticket));
                $staffIds[] = (int) $receiver->id;
            }

            $owner = User::query()->find($ticket->owner_id);
            if ($owner && ! in_array((int) $owner->id, $staffIds, true)) {
                HelpdeskNotifier::send($owner, new TicketSubmittedNotification($ticket));
            }
        });

        // Event listener untuk event 'updated'
        static::updated(function ($ticket) {
            if (! self::$isSeeding && $ticket->wasChanged('unit_id')) {
                foreach ($ticket->newTicketRecipients() as $receiver) {
                    HelpdeskNotifier::send($receiver, new NewTicketNotification($ticket));
                }
            }

            if (! self::$isSeeding && $ticket->wasChanged('ticket_statuses_id')) {
                $status = (int) $ticket->ticket_statuses_id;
                if (in_array($status, [TicketStatus::IN_PROGRESS, TicketStatus::CANCEL, TicketStatus::CLOSED], true)) {
                    $owner = User::query()->find($ticket->owner_id);
                    if ($owner) {
                        HelpdeskNotifier::send($owner, new TicketStatusChangedNotification($ticket, $status));
                    }
                }
            }

            TicketHistory::create([
                'ticket_id' => $ticket->id,
                'ticket_statuses_id' => $ticket->ticket_statuses_id,
                'user_id' => Auth::id(),
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Penerima notifikasi tiket baru. Para PIC default kategori yang masih
     * eligible menang; bila tidak ada, penanggung jawab tiket (mis. hasil
     * pemetaan lama BUSDEV); bila tidak ada juga, seluruh anggota aktif
     * divisi tujuan ditambah admin global.
     *
     * @return Collection<int, User>
     */
    public function newTicketRecipients(): Collection
    {
        $unitId = (int) $this->unit_id;

        $category = $this->problem_category_id
            ? ProblemCategory::query()->with('defaultResponsibles')->find($this->problem_category_id)
            : null;

        $pics = $category?->eligibleDefaultResponsibles($unitId) ?? new Collection;
        if ($pics->isNotEmpty()) {
            return $pics;
        }

        $responsible = $this->eligibleResponsible();
        if ($responsible) {
            return new Collection([$responsible]);
        }

        return User::query()
            ->ticketProcessorsForUnit($unitId, includeGlobal: true)
            ->get();
    }

    private static function defaultResponsibleIdForCategory(self $ticket): ?int
    {
        $category = ProblemCategory::query()
            ->whereKey($ticket->problem_category_id)
            ->first(['id', 'unit_id', 'name']);

        if (! $category) {
            return null;
        }

        $configured = $category->pickDefaultResponsible((int) $ticket->unit_id);
        if ($configured) {
            return (int) $configured->getKey();
        }

        $assigneeName = HelpdeskOperationalClassifier::defaultAssigneeName(trim((string) $category->name));
        if ($assigneeName === null) {
            return null;
        }

        $candidate = User::query()
            ->where('name', $assigneeName)
            ->first();

        return $candidate?->isActiveTicketProcessorForUnit((int) $ticket->unit_id)
            ? $candidate->getKey()
            : null;
    }
}
