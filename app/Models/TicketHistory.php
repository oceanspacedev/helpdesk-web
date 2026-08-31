<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TicketHistory extends Model
{
    use HasFactory;

    protected $table = 'ticket_histories';

    protected $casts = [
        'ticket_id' => 'int',
        'ticket_statuses_id' => 'int',
        'user_id' => 'int',
    ];

    protected $fillable = [
        'ticket_id',
        'ticket_statuses_id',
        'user_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function ticket()
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }

    public function ticketStatus()
    {
        return $this->belongsTo(TicketStatus::class, 'ticket_statuses_id');
    }
}
