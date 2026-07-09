<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use Carbon\Carbon;
use Filament\Panel;
use BezhanSalleh\FilamentShield\Traits\HasPanelShield;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Notifications\Notifiable;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Class User.
 *
 * @property int $id
 * @property null|int $unit_id
 * @property string $name
 * @property string $email
 * @property null|Carbon $email_verified_at
 * @property null|string $password
 * @property null|string $two_factor_secret
 * @property null|string $two_factor_recovery_codes
 * @property null|Carbon $two_factor_confirmed_at
 * @property null|string $remember_token
 * @property null|Carbon $created_at
 * @property null|Carbon $updated_at
 * @property null|string $identity
 * @property null|string $phone
 * @property null|int $user_level_id
 * @property bool $is_active
 * @property null|string $deleted_at
 * @property null|Unit $unit
 * @property Collection|Comment[] $comments
 * @property Collection|Ticket[] $tickets
 */
class User extends Authenticatable implements FilamentUser, MustVerifyEmail
{
    use SoftDeletes, HasRoles, HasPanelShield, HasFactory, Notifiable;
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

    /**
     * Mendapatkan semua entitas yang terkait dengan pengguna.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function entities()
    {
        return $this->morphMany(UserEntity::class, 'entity');
    }

    /**
     * Mendapatkan semua unit yang terkait dengan pengguna.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasManyThrough
     */
    public function units()
    {
        return $this->morphedByMany(Unit::class, 'entity', 'user_entities');
    }


    /**
     * Get the unit that owns the User.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    // public function unit()
    // {
    //     return $this->belongsTo(Unit::class);
    // }

    /**
     * Get all of the comments for the User.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function comments()
    {
        return $this->hasMany(Comment::class);
    }

    /**
     * Get all of the tickets for the User.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function tickets()
    {
        return $this->hasMany(Ticket::class, 'owner_id');
    }

    /**
     * Get all of the ticekt responsibility for the User.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
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
        return (bool) $this->is_active;
    }

    /**
     * Add scope to display users based on their role.
     *
     * If the role is as an admin unit, then display the user based on their unit ID.
     */
    public function scopeByRole($query)
    {
        if (auth()->user()->hasRole('Admin Unit')) {
            return $query->whereHas('units', function ($q) {
                $q->whereIn('id', auth()->user()->units->pluck('id'));
            });
        }
    }

    /**
     * Get all of the socialiteUsers for the User
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function socialiteUsers()
    {
        return $this->hasMany(SocialiteUser::class);
    }
}
