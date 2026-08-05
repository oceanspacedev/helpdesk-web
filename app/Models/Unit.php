<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class Unit.
 *
 * @property int $id
 * @property string $name
 * @property Collection|ProblemCategory[] $problem_categories
 * @property Collection|Ticket[] $tickets
 * @property Collection|User[] $users
 */
class Unit extends Model
{
    use SoftDeletes;
    public $timestamps = false;

    protected $table = 'units';

    protected $fillable = [
        'name',
    ];

    /**
     * Get all of the problemCategories for the Unit
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function problemCategories()
    {
        return $this->hasMany(ProblemCategory::class);
    }

    /**
     * Get all of the tickets for the Unit
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function tickets()
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * Mendapatkan semua pengguna yang terkait dengan unit melalui user entities.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphToMany
     */
    public function users()
    {
        return $this->morphToMany(User::class, 'entity', 'user_entities');
    }

    /**
     * Get all of the SLAs for the Unit
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function slas()
    {
        return $this->hasMany(UnitSla::class);
    }
}
