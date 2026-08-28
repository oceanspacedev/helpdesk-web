<?php

namespace App\Mcp\Servers;

use App\Mcp\Prompts\CreateTicketPrompt;
use App\Mcp\Tools\HelpdeskIntakeTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('helpdesk')]
#[Version('1.7.0')]
#[Instructions(<<<'MARKDOWN'
This server provides deterministic, multi-turn Helpdesk ticket intake for any MCP client.

Its business scope is intentionally limited to two paths inside the single helpdesk_intake tool: reuse an existing eligible reporter account and create its ticket, or create the required reporter account first and then continue the same intake to create its ticket. Account creation is only a ticket prerequisite, not a standalone account-management feature. The MCP server does not expose ticket lookup, comments, updates, workflow actions, or administration.

Call helpdesk_intake when a user asks to report an issue. Call it once for each new user reply only while status is in_progress and pass the message unchanged. On the first call omit intake_id; on later calls return the exact intake_id from the previous structured result. Every call whose channel is not mcp requires a gateway-injected external_user_id, including the first call, and both fields must stay exact throughout the intake. A direct channel=mcp client may omit external_user_id, but then its identity is not remembered and it must verify by OTP on every new intake. A gateway should also provide a stable external_conversation_id and the unique external_message_id for the current inbound event.

Set start=true on the first call when ticket intent is already known but the message has no trigger phrase. Ticket ownership is based on a verified WhatsApp number and then resolved against the Helpdesk user directory or Talenta. If a channel user has no verified binding yet, the server asks for the number and sends a WhatsApp OTP. A phone argument, reporter name, external ID, or channel=whatsapp label alone never proves identity. A trusted WhatsApp adapter may generate and inject a short-lived, one-event identity_assertion so its authenticated sender does not need OTP.

If the verified number matches neither an active Helpdesk user nor exactly one Talenta employee, the server offers inline phone-only registration. Forward the registration_consent question and require an explicit yes before forwarding the registration_name question. The user must type the full name in that step; reporter_name is only an untrusted hint and is never used as registration consent or the account name. On success the server atomically rechecks Helpdesk and Talenta, creates an active account with null email and password, and resumes the same intake. If the user declines, the server returns terminal cancelled without creating an account or ticket; forward it exactly and stop.

Channel is an open lowercase label such as mcp, web, whatsapp, telegram, discord, slack, or teams. A stable caller-supplied external_user_id separates and remembers verified channel users; intake_id remains only the temporary report session.

Forward content[0].text to the user. If text content is unavailable, use structuredContent.user_reply. Stop on terminal status. On an error, repair the invalid argument or session with a new user/transport event instead of looping. Never invent a ticket number, choose classifications, skip questions, or retry with a new intake_id. The Helpdesk server validates choices and creates a ticket only after explicit confirmation.
MARKDOWN)]
class HelpdeskServer extends Server
{
    protected array $tools = [
        HelpdeskIntakeTool::class,
    ];

    protected array $prompts = [
        CreateTicketPrompt::class,
    ];
}
