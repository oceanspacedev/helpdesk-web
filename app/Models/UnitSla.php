<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UnitSla extends Model
{
    use HasFactory;

    protected $fillable = [
        'unit_id',
        'priority_id',
        'target_hours',
    ];

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function priority()
    {
        return $this->belongsTo(Priority::class);
    }
}
