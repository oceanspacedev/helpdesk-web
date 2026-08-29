<?php

use App\Mcp\Servers\HelpdeskServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp/helpdesk', HelpdeskServer::class)
    ->middleware(['throttle:mcp-pre-auth', 'helpdesk.mcp', 'throttle:mcp']);

Mcp::local('helpdesk', HelpdeskServer::class);
