# UC-002 Manage Ticket Workflow

Status: Reviewed

## Evidence

Observed in `ViewTicket.php`, `TicketPolicy.php`, `TicketResource.php`, and `Ticket.php`.

## Flow

- Trigger: Admin Unit, Staff Unit, or a global administrator opens a ticket received by a unit they serve.
- Preconditions: The ticket's destination `unit_id` belongs to the user's assigned units, unless the user is a global administrator; the ticket is not already closed or cancelled.
- Main path:
  1. The destination processor sees the report content without an Edit action.
  2. If ticket is Open and unassigned or assigned to current user, system shows Proses and Batalkan actions.
  3. If ticket is In Progress and assigned to current user, system shows Batalkan and Selesai actions.
  4. If its previous responsible user is no longer active, authorized, or assigned to the destination unit, another eligible destination processor sees Ambil Alih before continuing the workflow.
  5. User selects an action.
  6. System atomically assigns `responsible_id` to current user and sets status to Cancel, In Progress, or Closed.
  7. Ticket model events update dates, create history, and send notifications when applicable.
- Exceptions: The sender may view a cross-unit outgoing ticket but cannot process or edit it unless the sender also serves the destination unit. Policy blocks unrelated units and Cancelled/Closed/soft-deleted tickets. A non-responsible processor cannot transition an In Progress ticket while the current responsible remains eligible, but may take over after that eligibility is lost.
- Postconditions: Ticket status, responsible user, history, and status dates reflect transition.

## Data Used

ENT-001 User, ENT-002 Ticket, ENT-006 TicketStatus, ENT-009 TicketHistory.

## Acceptance Criteria

- AC-UC-002-01: Eligible Admin Unit and Staff Unit members of the destination can claim an Open ticket.
- AC-UC-002-02: In Progress tickets can be closed or cancelled by the responsible user.
- AC-UC-002-03: Closed tickets have `solved_at`.
- AC-UC-002-04: Updates create ticket history.
- AC-UC-002-05: The sending unit and unrelated units cannot run workflow transitions.
- AC-UC-002-06: Destination processors cannot alter the sender's title, description, attachments, priority, entity, destination, or category through Edit; workflow uses the dedicated process ability.
- AC-UC-002-07: An ineligible previous responsible user does not lock the ticket or receive processor notifications; another eligible destination processor can take it over.
