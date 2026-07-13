# UC-005 ITA Get Or Comment Ticket

Status: Reviewed

## Evidence

Observed in `WhatsappHelpdeskActionService.php` and `WhatsappHelpdeskActionTest.php`.

## Flow

- Trigger: ITA posts `helpdesk.get_ticket` or `helpdesk.add_comment`.
- Preconditions: Actor is verified and resolves to an active helpdesk user.
- Main path for get:
  1. System extracts ticket id from `ticket_ref` or top-level ticket fields.
  2. System confirms actor can access the ticket.
  3. System returns ticket resource.
- Main path for comment:
  1. System extracts and authorizes ticket.
  2. System reads comment text from `ticket_data.description` or message text.
  3. System appends attachment links when present.
  4. System creates comment as actor user and returns ticket plus comment metadata.
- Exceptions: Missing ticket returns 404; forbidden ticket returns 403; missing comment returns 422; unsupported action returns 422.
- Postconditions: Get does not change ticket. Comment creates a comment without changing ticket status.

## Data Used

ENT-001 User, ENT-002 Ticket, ENT-008 Comment.

## Acceptance Criteria

- AC-UC-005-01: Authorized actor can retrieve owned or assigned ticket.
- AC-UC-005-02: Authorized actor can add a comment.
- AC-UC-005-03: Unsupported close action is rejected.
- AC-UC-005-04: Comment action does not close the ticket.

