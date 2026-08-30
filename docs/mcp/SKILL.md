---
name: helpdesk-create-ticket
description: Use the vendor-neutral helpdesk_intake MCP tool for deterministic multi-turn ticket intake.
---

# Helpdesk ticket intake

Use `helpdesk_intake` when the user asks to report an issue or create a Helpdesk ticket. This single tool has exactly two business paths: reuse an existing eligible reporter account and create its ticket, or create the required reporter account first and continue the same intake to create its ticket. Account creation exists only as a ticket prerequisite. Do not use this MCP server for ticket lookup, comments, updates, workflow actions, or administration. Treat Codex, Atlas relaying WhatsApp, and every other AI host or channel bridge as generic clients of this same contract.

1. On the first call, pass the user's message unchanged and omit `intake_id`. Set `start=true` if ticket intent is already known but the message has no trigger phrase.
2. Forward only `content[0].text`; if unavailable, forward `structuredContent.user_reply`. Copy it verbatim. Do not paraphrase, translate, or add extra questions.
3. While status is `in_progress`, call the tool once per new user reply and pass the exact `intake_id` returned previously. Every external-channel call requires the exact same gateway-injected `channel` and `external_user_id`, including the first call.
4. Stop when `terminal=true` or the tool returns an error. Repair invalid input/session context from the gateway; do not loop calls without a new user event.

A gateway should inject `external_conversation_id`, `external_user_id`, the current `external_message_id`, `channel`, and any attachment URLs. Do not infer or expose those IDs. Never invent `conversation_id` or reuse one ID across users; on a shared MCP transport such as Atlas, omit it unless the host injects a unique per-chat value and always return the previous `intake_id`. A direct `channel=mcp` client may omit `external_user_id`, but then it has no durable reporter binding and must verify by OTP on every new intake. For a multi-user gateway, `external_user_id` must be stable and sender-specific; never substitute a shared group or thread ID. It separates channel users and retrieves a verified binding, but never becomes or proves the ticket owner. The server verifies a WhatsApp number by OTP, checks Helpdesk/Talenta, and then binds only a stable external user for later intakes.

If the verified number exists in neither Helpdesk nor Talenta, forward the server's `registration_consent` question. Only an explicit yes from the user may advance to `registration_name`. Forward that question too and pass the freshly typed full name unchanged; never reuse `reporter_name` as consent or account data. The server rechecks both directories, atomically creates a phone-only account with null email and password, and continues the same intake. If the user declines and the server returns terminal `cancelled`, forward it and stop; no account or ticket was created, and there is no web redirect to follow.

Forward phone and OTP questions exactly. A `phone`, `reporter_name`, or `channel=whatsapp` argument alone is not proof. Only a trusted WhatsApp webhook gateway may generate and inject the one-event `identity_assertion` together with `external_message_id`; the model must never generate or request it. A `v2` assertion also binds the SHA-256 of the exact inbound message.

After identity, the reporter describes the issue once. The server writes the title and classifies only from unambiguous ticket language, or marks the ticket `Perlu diklasifikasi` for staff. Unnamed branches are stored as `Belum disebutkan`. Show `content[0].text` verbatim with no prefix. The recap is the stored story; if it is not what the user typed, they reply `ulangi`. The user replies `ya` or `ulangi`. Do not quiz the user, pick numbered options, echo the recap back into the tool, or invent an `HD-...` number.
