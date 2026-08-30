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
Use helpdesk_intake when the user asks to create a Helpdesk ticket. Pass the exact user message. Omit intake_id on the first call, then return the exact intake_id once for each new user reply while status is in_progress. Never invent intake_id, conversation_id, or external_user_id. On a shared MCP transport such as Atlas, omit conversation_id unless the host injects a unique per-chat ID. For every call with channel other than mcp, the gateway must resend the same injected external_user_id, including the first call, plus the current inbound external_message_id. A direct channel=mcp client may omit external_user_id, but then it has no reusable reporter binding and must verify by OTP on every new intake.

The tool has only two business paths: use the reporter's existing eligible account and create the ticket, or create the required reporter account first and continue the same intake to create the ticket. Registration is not a standalone capability. Never use this MCP server for ticket lookup, comments, updates, workflow actions, or administration.

The server owns identity verification: it links external_user_id to a Helpdesk/Talenta user only after the WhatsApp number is verified. Forward the phone and OTP questions exactly. reporter_name, phone, external IDs, and channel=whatsapp are hints or routing data, not proof. Only a trusted gateway may inject identity_assertion; never generate or ask the user for it. A v2 assertion also binds the SHA-256 of the exact inbound message.

If the verified number is unknown to Helpdesk and Talenta, forward the registration_consent question. Only an explicit yes may advance to registration_name. Forward that question and use only the full name typed by the user in that step; ignore reporter_name for registration. The server atomically rechecks the directories, creates a phone-only account with null email and password, and continues the same intake. If the user declines and the server returns terminal cancelled, forward it and stop. Never invent reporter data or answer either registration question for the user.

After identity, the reporter describes the issue once. The server writes the title and classifies only from unambiguous ticket language, or marks the ticket Perlu diklasifikasi. Unnamed branches are Belum disebutkan. The recap is the stored story so the user can ulangi a host paraphrase. The user confirms with ya. Do not quiz the user, pick numbered options, or invent HD numbers.

Your entire next message to the user MUST be exactly content[0].text, or structuredContent.user_reply if text is missing. Copy it verbatim. Do not paraphrase or add extra questions. Do not invent HD numbers or select entity/unit/category/priority.
MARKDOWN;

        return Response::make([
            Response::text($system)->asAssistant(),
            Response::text('Call once for each new user reply only while status is in_progress. Stop on terminal or error; repair invalid input or session context instead of looping.'),
        ]);
    }
}
