<?php

use App\Mcp\Servers\HelpdeskServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp/helpdesk', HelpdeskServer::class)
    ->middleware(['helpdesk.mcp', 'throttle:mcp']);

Mcp::local('helpdesk', HelpdeskServer::class);
