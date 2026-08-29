<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HelpdeskMcpSetting extends Model
{
    public const SINGLETON_ID = 1;

    protected $table = 'helpdesk_mcp_settings';

    protected $guarded = [];

    protected $hidden = [
        'tokens',
        'identity_pepper',
        'identity_assertion_secret',
    ];

    protected function casts(): array
    {
        return [
            'tokens' => 'encrypted:array',
            'intake_ttl_minutes' => 'integer',
            'rate_limit_per_minute' => 'integer',
            'identity_pepper' => 'encrypted',
            'identity_assertion_secret' => 'encrypted',
            'identity_assertion_leeway_seconds' => 'integer',
        ];
    }
}
