<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class McpTicketCreationRequest extends Model
{
    protected $table = 'mcp_ticket_creation_requests';

    protected $fillable = [
        'client_key',
        'key_hash',
        'request_hash',
        'status',
        'response',
    ];

    protected $casts = [
        'response' => 'array',
    ];
}
