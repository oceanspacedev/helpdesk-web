<?php

namespace App\Mcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Prompt;

#[Name('create_ticket_flow')]
#[Description('Create a Helpdesk ticket, creating its verified reporter account first only when required.')]
class CreateTicketPrompt extends Prompt
{
    public function handle(Request $request): ResponseFactory
    {
        $system = <<<'MARKDOWN'
Use helpdesk_intake when the user asks to create a Helpdesk ticket. Pass the exact user message. Omit intake_id on the first call, then return the exact intake_id once for each new user reply while status is in_progress. For every call with channel other than mcp, the gateway must resend the same injected external_user_id, including the first call, plus the current inbound external_message_id. A direct channel=mcp client may omit external_user_id, but then it has no reusable reporter binding and must verify by OTP on every new intake.

The tool has only two business paths: use the reporter's existing eligible account and create the ticket, or create the required reporter account first and continue the same intake to create the ticket. Registration is not a standalone capability. Never use this MCP server for ticket lookup, comments, updates, workflow actions, or administration.

The server owns identity verification: it links external_user_id to a Helpdesk/Talenta user only after the WhatsApp number is verified. Forward the phone and OTP questions exactly. reporter_name, phone, external IDs, and channel=whatsapp are hints or routing data, not proof. Only a trusted gateway may inject identity_assertion; never generate or ask the user for it.

If the verified number is unknown to Helpdesk and Talenta, forward the registration_consent question. Only an explicit yes may advance to registration_name. Forward that question and use only the full name typed by the user in that step; ignore reporter_name for registration. The server atomically rechecks the directories, creates a phone-only account with null email and password, and continues the same intake. If the user declines and the server returns terminal cancelled, forward it and stop. Never invent reporter data or answer either registration question for the user.

Forward only content[0].text, or structuredContent.user_reply when text content is unavailable. Do not invent HD numbers, select entity/unit/category/priority, or skip a server question.
MARKDOWN;

        return Response::make([
            Response::text($system)->asAssistant(),
            Response::text('Call once for each new user reply only while status is in_progress. Stop on terminal or error; repair invalid input or session context instead of looping.'),
        ]);
    }
}
