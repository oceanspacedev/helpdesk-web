<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use App\Notifications\NewTicketNotification;
use App\Notifications\TicketStatusChangedNotification;
use App\Notifications\TicketSubmittedNotification;
use App\Services\Integrations\HelpdeskOperationalClassifier;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

/**
 * Class Ticket.
 *
 * @property int $id
 * @property int $priority_id
 * @property int $unit_id
 * @property int $owner_id
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
     * Get the businessEntity that owns the Ticket.
     *
     * @return BelongsTo
     */
    public function businessEntity()
    {
        return $this->belongsTo(BusinessEntity::class, 'business_entities_id');
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

        // Event listener untuk event 'saving'
        static::saving(function ($ticket) {
            // Auto-assign only named BUSDEV document types to the OD accounts.
            // Odoo, printer, CCTV, and holding tickets stay on the unit queue.
            if (! self::$isSeeding && $ticket->isDirty('problem_category_id')) {
                $ticket->responsible_id = self::defaultResponsibleIdForCategory($ticket);
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
                if (Schema::hasTable('unit_slas')) {
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

            // Membuat riwayat tiket baru
            TicketHistory::create([
                'ticket_id' => $ticket->id,
                'ticket_statuses_id' => $ticket->ticket_statuses_id,
                'user_id' => Auth::id(),
                'created_at' => now(),
            ]);

            $staffIds = [];
            $responsible = $ticket->eligibleResponsible();

            if ($responsible) {
                $responsible->notify(new NewTicketNotification($ticket));
                $staffIds[] = (int) $responsible->id;
            } else {
                $receivers = User::query()
                    ->ticketProcessorsForUnit((int) $ticket->unit_id, includeGlobal: true)
                    ->get();

                foreach ($receivers as $receiver) {
                    $receiver->notify(new NewTicketNotification($ticket));
                    $staffIds[] = (int) $receiver->id;
                }
            }

            $owner = User::query()->find($ticket->owner_id);
            if ($owner && ! in_array((int) $owner->id, $staffIds, true)) {
                $owner->notify(new TicketSubmittedNotification($ticket));
            }
        });

        // Event listener untuk event 'updated'
        static::updated(function ($ticket) {
            if (! self::$isSeeding && $ticket->wasChanged('unit_id')) {
                $responsible = $ticket->eligibleResponsible();
                if ($responsible) {
                    $responsible->notify(new NewTicketNotification($ticket));
                } else {
                    $receivers = User::query()
                        ->ticketProcessorsForUnit((int) $ticket->unit_id, includeGlobal: true)
                        ->get();

                    foreach ($receivers as $receiver) {
                        $receiver->notify(new NewTicketNotification($ticket));
                    }
                }
            }

            if (! self::$isSeeding && $ticket->wasChanged('ticket_statuses_id')) {
                $status = (int) $ticket->ticket_statuses_id;
                if (in_array($status, [TicketStatus::IN_PROGRESS, TicketStatus::CANCEL, TicketStatus::CLOSED], true)) {
                    $owner = User::query()->find($ticket->owner_id);
                    if ($owner) {
                        $owner->notify(new TicketStatusChangedNotification($ticket, $status));
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

    private static function defaultResponsibleIdForCategory(self $ticket): ?int
    {
        $categoryName = trim((string) ProblemCategory::query()
            ->whereKey($ticket->problem_category_id)
            ->value('name'));
        $assigneeName = HelpdeskOperationalClassifier::defaultAssigneeName($categoryName);
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
