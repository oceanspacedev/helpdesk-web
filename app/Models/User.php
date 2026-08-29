<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use App\Support\PhoneNumber;
use BezhanSalleh\FilamentShield\Traits\HasPanelShield;
use Carbon\Carbon;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Traits\HasRoles;

/**
 * Class User.
 *
 * @property int $id
 * @property null|int $unit_id
 * @property string $name
 * @property null|string $email
 * @property null|Carbon $email_verified_at
 * @property null|string $email_verified_via
 * @property null|string $password
 * @property null|string $two_factor_secret
 * @property null|string $two_factor_recovery_codes
 * @property null|Carbon $two_factor_confirmed_at
 * @property null|string $remember_token
 * @property null|Carbon $created_at
 * @property null|Carbon $updated_at
 * @property null|string $identity
 * @property null|string $phone
 * @property null|string $phone_normalized
 * @property null|int $user_level_id
 * @property bool $is_active
 * @property null|string $deleted_at
 * @property null|Unit $unit
 * @property Collection|Comment[] $comments
 * @property Collection|Ticket[] $tickets
 */
class User extends Authenticatable implements FilamentUser, MustVerifyEmail
{
    use HasFactory, HasPanelShield, HasRoles, Notifiable, SoftDeletes;

    protected $table = 'users';

    protected $casts = [
        'unit_id' => 'int',
        'email_verified_at' => 'datetime',
        'two_factor_confirmed_at' => 'datetime',
        'user_level_id' => 'int',
        'is_active' => 'bool',
    ];

    protected $hidden = [
        'password',
        'two_factor_secret',
        'remember_token',
    ];

    protected $fillable = [
        'unit_id',
        'name',
        'email',
        'email_verified_at',
        'email_verified_via',
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
        'remember_token',
        'identity',
        'phone',
        'user_level_id',
        'is_active',
    ];

    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            $user->phone_normalized = PhoneNumber::canonical($user->phone);

            // Mengganti alamat email membatalkan bukti kepemilikan alamat lama.
            if ($user->exists && $user->isDirty('email')) {
                $user->email_verified_at = null;
                $user->email_verified_via = null;
            } elseif ($user->email_verified_at === null) {
                $user->email_verified_via = null;
            }
        });
    }

    /**
     * Email yang belum diverifikasi bukan credential login yang tepercaya.
     */
    public function getAuthPassword()
    {
        return $this->canUseVerifiedEmail() ? parent::getAuthPassword() : '';
    }

    public function canUseVerifiedEmail(): bool
    {
        return filled($this->email)
            && $this->hasVerifiedEmail()
            && in_array((string) $this->email_verified_via, [
                'admin',
                'email_link',
                'socialite',
            ], true);
    }

    /**
     * Mendapatkan semua entitas yang terkait dengan pengguna.
     *
     * @return MorphMany
     */
    public function entities()
    {
        return $this->morphMany(UserEntity::class, 'entity');
    }

    /**
     * Mendapatkan semua unit yang terkait dengan pengguna.
     *
     * @return HasManyThrough
     */
    public function units()
    {
        return $this->morphedByMany(Unit::class, 'entity', 'user_entities');
    }

    /**
     * Unit kerja yang berlaku untuk inbox tiket.
     *
     * Relasi user_entities adalah sumber utama. Kolom users.unit_id hanya
     * menjadi fallback ketika tabel relasi belum tersedia (misalnya migrasi
     * lama pada lingkungan pengujian).
     *
     * @return list<int>
     */
    public function assignedUnitIds(): array
    {
        if (Schema::hasTable('user_entities')) {
            $unitIds = $this->relationLoaded('units')
                ? $this->units->modelKeys()
                : $this->units()->pluck('units.id')->all();
        } elseif ($this->unit_id) {
            $unitIds = [];
            $unitIds[] = (int) $this->unit_id;
        } else {
            $unitIds = [];
        }

        return collect($unitIds)
            ->map(static fn ($unitId): int => (int) $unitId)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function hasGlobalTicketAccess(): bool
    {
        return $this->hasAnyRole(['Super Admin', 'Master Admin']);
    }

    public function canProcessTickets(): bool
    {
        return $this->hasGlobalTicketAccess()
            || $this->hasAnyRole(['Admin Unit', 'Staff Unit']);
    }

    public function canProcessTicketsForUnit(?int $unitId): bool
    {
        if ($this->hasGlobalTicketAccess()) {
            return true;
        }

        return $unitId !== null
            && $this->canProcessTickets()
            && $this->isAssignedToUnit($unitId);
    }

    public function isAssignedToUnit(?int $unitId): bool
    {
        return $unitId !== null
            && in_array($unitId, $this->assignedUnitIds(), true);
    }

    public function canAdministerUnit(?int $unitId): bool
    {
        if ($this->hasGlobalTicketAccess()) {
            return true;
        }

        return $unitId !== null
            && $this->hasRole('Admin Unit')
            && $this->isAssignedToUnit($unitId);
    }

    public function isActiveTicketProcessorForUnit(?int $unitId): bool
    {
        return $this->is_active
            && $this->can('Update:Ticket')
            && $this->canProcessTicketsForUnit($unitId);
    }

    public function scopeAssignedToUnit(Builder $query, int $unitId): Builder
    {
        if (Schema::hasTable('user_entities')) {
            return $query->whereHas(
                'units',
                fn (Builder $units): Builder => $units->whereKey($unitId),
            );
        }

        return $query->where('users.unit_id', $unitId);
    }

    public function scopeTicketProcessorsForUnit(
        Builder $query,
        int $unitId,
        bool $includeGlobal = false,
    ): Builder {
        $permissionTable = config('permission.table_names.permissions', 'permissions');

        if (! Schema::hasTable($permissionTable)
            || ! DB::table($permissionTable)
                ->where('name', 'Update:Ticket')
                ->where('guard_name', 'web')
                ->exists()) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where('users.is_active', true)
            ->permission('Update:Ticket')
            ->where(function (Builder $query) use ($unitId, $includeGlobal): void {
                if ($includeGlobal) {
                    $query->whereHas(
                        'roles',
                        fn (Builder $roles): Builder => $roles->whereIn('name', ['Super Admin', 'Master Admin']),
                    )->orWhere(function (Builder $query) use ($unitId): void {
                        $query->whereHas(
                            'roles',
                            fn (Builder $roles): Builder => $roles->whereIn('name', ['Admin Unit', 'Staff Unit']),
                        )->assignedToUnit($unitId);
                    });

                    return;
                }

                $query->whereHas(
                    'roles',
                    fn (Builder $roles): Builder => $roles->whereIn('name', ['Admin Unit', 'Staff Unit']),
                )->assignedToUnit($unitId);
            });
    }

    /**
     * Get all of the comments for the User.
     *
     * @return HasMany
     */
    public function comments()
    {
        return $this->hasMany(Comment::class);
    }

    /**
     * Get all of the tickets for the User.
     *
     * @return HasMany
     */
    public function tickets()
    {
        return $this->hasMany(Ticket::class, 'owner_id');
    }

    /**
     * Get all of the ticekt responsibility for the User.
     *
     * @return HasMany
     */
    public function ticektResponsibility()
    {
        return $this->hasMany(Ticket::class, 'responsible_id');
    }

    /**
     * Determine who has access.
     *
     * Only active users can access the filament
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->canUseVerifiedEmail()) {
            return true;
        }

        return (int) session()->get('helpdesk_phone_verified_user_id') === (int) $this->id;
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(config('filament-shield.super_admin.name', 'Super Admin'));
    }

    /**
     * Add scope to display users based on their role.
     *
     * If the role is as an admin unit, then display the user based on their unit ID.
     */
    public function scopeByRole(Builder $query): Builder
    {
        $user = auth()->user();

        if ($user?->hasRole('Admin Unit') && ! $user->hasGlobalTicketAccess()) {
            return $query->whereHas(
                'units',
                fn (Builder $units): Builder => $units->whereIn('units.id', $user->assignedUnitIds()),
            );
        }

        return $query;
    }

    /**
     * Get all of the socialiteUsers for the User
     *
     * @return HasMany
     */
    public function socialiteUsers()
    {
        return $this->hasMany(SocialiteUser::class);
    }
}
