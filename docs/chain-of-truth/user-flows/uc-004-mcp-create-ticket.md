# UC-004 MCP Create Ticket

Status: Reviewed

## Evidence

Observed in `routes/ai.php`, `HelpdeskIntakeTool.php`, `HelpdeskIntakeSession.php`, `HelpdeskTicketCreationService.php`, `HelpdeskClassificationResolver.php`, `HelpdeskFormOptionsService.php`, and the MCP feature tests.

## Flow

- Trigger: A generic MCP client or gateway—such as Codex, Atlas relaying WhatsApp, another AI host, or another channel bridge—calls `helpdesk_intake` for a user's report. Every client uses the same vendor-neutral contract.
- Preconditions: The MCP client is authorized for the selected transport. Reporter phone ownership is proven through OTP or a trusted one-event WhatsApp assertion; routing and display-name fields are not identity proof. External-channel calls provide a stable `external_user_id` and preserve the same channel throughout the intake.
- Main path:
  1. The server creates or resumes an intake using its opaque `intake_id` and immutable routing context.
  2. The server verifies one canonical reporter phone and selects one of exactly two business paths.
  3. If an eligible Helpdesk account exists, the server reuses it. If the account is missing, the server creates the prerequisite account before continuing: an exact Talenta match may supply its data server-side, while a phone absent from both directories requires explicit consent and a freshly typed full name before atomic phone-only account creation.
  4. The server collects and validates issue details, business entity, unit, category, priority, and optional attachments one step at a time.
  5. The server presents the complete ticket draft and requires explicit confirmation.
  6. The server revalidates reporter ownership, creates the Open ticket and history, and stores supporting attachment URLs.
  7. The server returns a terminal `ticket_created` result with the ticket summary.
- Alternatives: A trusted WhatsApp gateway assertion can replace OTP for its authenticated sender. A stable external user may reuse a previously verified reporter binding. A local MCP client without `external_user_id` verifies by OTP on every new intake.
- Exceptions: Invalid classifications return the relevant form options without advancing. Unverified, conflicting, ambiguous, or LID-only identities are rejected safely. Declining the required account creation returns terminal `cancelled`; missing consent, invalid name, directory changes, or collisions create no account. These outcomes create no ticket, and MCP returns no web URL or redirect. Phone verification never marks email verified.
- Postconditions: Ticket and history exist under an active Helpdesk user, a reporter provisioned from exactly one Talenta match, or a reporter created through consent-gated MCP inline registration. A manually registered reporter is active and phone-only with null email and password. Durable bindings exist only for stable external users.

## Data Used

ENT-001 User, ENT-002 Ticket, ENT-003 Unit, ENT-004 ProblemCategory, ENT-005 Priority, ENT-006 TicketStatus, ENT-007 BusinessEntity, ENT-009 TicketHistory.

## Acceptance Criteria

- AC-UC-004-01: A verified reporter with an eligible account can create a ticket through `helpdesk_intake` without opening or signing in to the web Helpdesk form.
- AC-UC-004-02: Exact retries and repeated final submission do not create duplicate tickets.
- AC-UC-004-03: Reporter phone fields must normalize to one canonical phone; conflicting phone claims cannot select another Helpdesk owner.
- AC-UC-004-04: Missing or invalid classification returns focused guidance and current form options.
- AC-UC-004-05: When the eligible Helpdesk account is missing, exactly one Talenta phone match may supply the prerequisite account data server-side; a zero-match reporter requires explicit consent and a freshly typed full name before atomic inline account creation.
- AC-UC-004-06: Declining the required account creation returns terminal `cancelled` and creates neither an account nor a ticket; accepting continues the same intake and preserves its original issue through ticket creation.
- AC-UC-004-07: Durable reporter bindings require a stable `external_user_id`; a local MCP client without one verifies by OTP on every new intake.
- AC-UC-004-08: The MCP advertises only `helpdesk_intake`; ticket lookup, comments, updates, workflow actions, administration, and standalone account management are not exposed.
