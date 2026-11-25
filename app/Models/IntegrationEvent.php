<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Jejak event_id webhook yang sudah diproses, agar event yang dikirim ulang
 * sistem eksternal hanya berefek sekali.
 */
class IntegrationEvent extends Model
{
    public $timestamps = false;

    protected $table = 'integration_events';

    protected $fillable = [
        'integration_id',
        'event_id',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'integration_id' => 'integer',
            'received_at' => 'datetime',
        ];
    }
}
