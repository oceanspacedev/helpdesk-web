# UC-004 ITA Create Ticket

Status: Reviewed

## Evidence

Observed in `routes/api.php`, `WhatsappHelpdeskActionService.php`, `WhatsappHelpdeskClassificationResolver.php`, `WhatsappHelpdeskFormOptionsService.php`, and `WhatsappHelpdeskActionTest.php`.

## Flow

- Trigger: ITA posts `helpdesk.create_ticket` to `API-003`.
- Preconditions: Actor payload includes `is_verified` truthy.
- Main path:
  1. System checks supported action.
  2. System resolves actor user by phone, id, or email.
  3. If no user exists for create action, system auto-registers a phone-only reporter when a valid phone is available.
  4. System validates issue summary, affected system, impact, consent, business entity, unit, category, and priority.
  5. System builds HTML description and attachment links.
  6. System creates ticket with Open status and stores supporting attachment URLs.
  7. System returns ticket resource and idempotency metadata when key is supplied.
- Alternatives: Classification validation can be performed through `API-002` before final action call.
- Exceptions: Missing or unknown fields return 422 with form options; unverified actors return 403; LID-only actor phone returns forbidden for create.
- Postconditions: Ticket and history exist, reporter may exist with null email and password.

## Data Used

ENT-001 User, ENT-002 Ticket, ENT-003 Unit, ENT-004 ProblemCategory, ENT-005 Priority, ENT-006 TicketStatus, ENT-007 BusinessEntity, ENT-009 TicketHistory.

## Acceptance Criteria

- AC-UC-004-01: Verified registered actor can create a ticket.
- AC-UC-004-02: Same idempotency key does not create duplicate tickets.
- AC-UC-004-03: Phone match takes precedence over email metadata.
- AC-UC-004-04: Missing classification returns form options.
- AC-UC-004-05: Phone-only reporter is auto-registered without synthetic email.

