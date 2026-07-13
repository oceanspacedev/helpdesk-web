# UC-002 Manage Ticket Workflow

Status: Reviewed

## Evidence

Observed in `ViewTicket.php`, `TicketPolicy.php`, `TicketResource.php`, and `Ticket.php`.

## Flow

- Trigger: Admin Unit or Super Admin opens a ticket view page.
- Preconditions: User can view the ticket and ticket is not already closed or cancelled.
- Main path:
  1. System shows edit action.
  2. If ticket is Open and unassigned or assigned to current user, system shows Cancel, Proses, and Selesai actions.
  3. If ticket is In Progress and assigned to current user, system shows Cancel and Selesai actions.
  4. User selects an action.
  5. System assigns `responsible_id` to current user and sets status to Cancel, In Progress, or Closed.
  6. Ticket model events update dates, create history, and send notifications when applicable.
- Exceptions: Policy blocks updates for Cancelled or Closed tickets, and blocks owners from updating tickets after Open.
- Postconditions: Ticket status, responsible user, history, and status dates reflect transition.

## Data Used

ENT-001 User, ENT-002 Ticket, ENT-006 TicketStatus, ENT-009 TicketHistory.

## Acceptance Criteria

- AC-UC-002-01: Eligible admin users can transition Open tickets.
- AC-UC-002-02: In Progress tickets can be closed or cancelled by the responsible user.
- AC-UC-002-03: Closed tickets have `solved_at`.
- AC-UC-002-04: Updates create ticket history.

