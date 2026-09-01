<?php

use App\Mcp\Servers\HelpdeskServer;
use App\Mcp\Servers\HelpdeskStaffServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp/helpdesk', HelpdeskServer::class)
    ->middleware(['throttle:mcp-pre-auth', 'helpdesk.mcp', 'throttle:mcp']);

Mcp::web('/mcp/helpdesk/staff', HelpdeskStaffServer::class)
    ->middleware(['throttle:mcp-pre-auth', 'helpdesk.staff.mcp', 'throttle:mcp']);

Mcp::local('helpdesk', HelpdeskServer::class);
