# UC-003 Comment On Ticket

Status: Reviewed

## Evidence

Observed in `CommentsRelationManager.php`, `Comment.php`, and `comments` migration.

## Flow

- Trigger: A user opens a ticket and selects add comment.
- Preconditions: User can access the ticket relation manager.
- Main path:
  1. User enters rich-text comment.
  2. User optionally uploads one attachment.
  3. System sets `user_id` to authenticated user.
  4. System creates comment with `tiket_id`.
  5. System sends database/notification messages to ticket owner or support users.
- Exceptions: Empty comment is rejected.
- Postconditions: Comment is attached to ticket.

## Data Used

ENT-001 User, ENT-002 Ticket, ENT-008 Comment.

## Acceptance Criteria

- AC-UC-003-01: Comment creation stores authenticated user.
- AC-UC-003-02: Comment appears under the ticket.
- AC-UC-003-03: Comment notification behavior runs after creation.

