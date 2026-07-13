# UC-001 Create Web Ticket

Status: Reviewed

## Evidence

Observed in `TicketResource.php`, `CreateTicket.php`, ticket migrations, `Ticket.php`, and README.

## Flow

- Trigger: An authenticated active user opens the ticket create page.
- Preconditions: User can access the admin panel and has permission to create tickets.
- Main path:
  1. User selects work unit.
  2. User selects a problem category, constrained by selected unit when unit is present.
  3. User enters title and rich-text description.
  4. User optionally uploads supporting attachments within configured limits.
  5. User selects priority and business entity.
  6. System sets `owner_id` to authenticated user and `ticket_statuses_id` to Open.
  7. System creates ticket, history, and notifications.
- Alternatives: If a selected problem category does not belong to the selected unit, the form clears the category.
- Exceptions: Missing required fields block submission through Filament validation.
- Postconditions: Ticket exists with status Open and owner set.

## Data Used

ENT-001 User, ENT-002 Ticket, ENT-003 Unit, ENT-004 ProblemCategory, ENT-005 Priority, ENT-006 TicketStatus, ENT-007 BusinessEntity, ENT-009 TicketHistory.

## Acceptance Criteria

- AC-UC-001-01: New web ticket stores required fields and owner.
- AC-UC-001-02: New web ticket starts as Open.
- AC-UC-001-03: Ticket history is created after ticket creation.
- AC-UC-001-04: Supporting attachments respect configured file type and size limits.

