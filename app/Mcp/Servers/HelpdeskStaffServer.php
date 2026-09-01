<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\HelpdeskTicketWorkflowTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('helpdesk-staff')]
#[Version('1.0.0')]
#[Instructions(<<<'MARKDOWN'
This is the staff-only Helpdesk workflow server. The authenticated bearer credential is mapped server-side to exactly one Helpdesk staff account. Never ask for, infer, or pass a PIC, user ID, responsible ID, name, phone, or external user identity.

Call helpdesk_ticket_workflow only when the user's latest message is an explicit ticket command containing exactly one canonical ticket number and one unambiguous action. Pass that latest message unchanged in message. Supported examples are `HD-2026-00042 proses` and `HD-2026-00042 done`. Do not call for questions, negation, conditional language, quoted or forwarded commands, malformed numbers, multiple ticket numbers, conflicting actions, lookup requests, comments, or requests to assign somebody else. Ask the user to send one exact command instead.

The server parses the raw message deterministically, rechecks the authenticated staff account, destination-unit authorization, current PIC, and ticket state under a database row lock. Process changes Open to In Progress and assigns the authenticated staff member as PIC. Done changes only an In Progress ticket to Closed; it never skips Open directly to Closed. A retry by the same PIC is idempotent and does not create another history or notification.

Return content[0].text, or structuredContent.user_reply if text is missing, verbatim to the user. Never claim a transition succeeded unless the tool returns ok=true.
MARKDOWN)]
class HelpdeskStaffServer extends Server
{
    protected array $tools = [
        HelpdeskTicketWorkflowTool::class,
    ];
}
