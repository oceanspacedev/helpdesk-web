# UCIC UC-004 MCP Create Ticket

Status: Reviewed

## Contract

- Interfaces: token-protected MCP over HTTP at `MCP-001` and the local `helpdesk` MCP server at `MCP-002`; both expose only the vendor-neutral `helpdesk_intake` tool. Codex, Atlas relaying WhatsApp, other AI hosts, and other channel bridges are generic clients of this same contract.
- Client authentication: `MCP-001` requires a configured MCP bearer token. `MCP-002` uses a configured or process-local client identity. Credentials identify the calling client, not the reporter.
- Reporter verification: Reporter ownership is proven by WhatsApp OTP or a short-lived, one-event trusted WhatsApp assertion. `phone`, `reporter_name`, `external_user_id`, and `channel` are never sufficient proof by themselves.
- Required input: exact user `message`. An empty message is accepted only with at least one valid `attachment_urls` entry.
- Optional input: opaque `intake_id`, conversation and external routing IDs, `phone`, trusted `identity_assertion`, `channel`, untrusted `reporter_name`, HTTPS `attachment_urls`, and first-turn `start`.
- Routing continuity: Every continuation repeats the server-issued `intake_id`. External channels also repeat the same normalized `channel` and stable `external_user_id` used on the first call.
- Business paths: Exactly two paths exist. An eligible Helpdesk account matching the verified canonical phone is reused for ticket creation. If the account is missing, the server creates it as a prerequisite and then continues the same intake to ticket creation; exactly one Talenta match may supply its account data. Conflicting phone claims and ambiguous Helpdesk or Talenta matches are rejected.
- Inline account prerequisite: A verified phone absent from both directories advances to `registration_consent`. Only explicit acceptance advances to `registration_name`; the server then atomically rechecks both directories and creates a phone-only account from the freshly typed full name. Declining returns terminal `cancelled` and creates neither an account nor a ticket.
- Intake validation: The server gathers issue details, business entity, unit, problem category, priority, optional attachments, and explicit creation confirmation through deterministic steps. Invalid choices return a corrected prompt and current form options without advancing the intake.
- Success result: `status: ticket_created`, `ok: true`, `terminal: true`, `requires_input: false`, and the created ticket summary in structured content.
- Error and terminal results: Invalid input, invalid session, message conflict, cancellation, and creation failure are returned as structured MCP results without exposing framework debug details. MCP never redirects a declined account prerequisite to a web form.
- Side effects: Prerequisite account creation when no eligible account exists, ticket and history creation, notifications, durable ticket-creation idempotency, and a reporter binding only for a stable `external_user_id`. Account creation is never offered as a standalone MCP operation.
- Replay and idempotency: Exact retries use the same `external_message_id` and content to replay one step. Reusing that ID with changed content conflicts. Final ticket creation is keyed durably by client and intake-derived idempotency data, so retries cannot create a duplicate ticket.
- Excluded operations: Ticket lookup, comments, updates, workflow actions, administration, and standalone account management are outside this MCP contract.
