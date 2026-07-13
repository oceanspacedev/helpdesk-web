# UC-008 Manage Master Data And Users

Status: Reviewed

## Evidence

Observed in `UserResource.php`, `UnitResource.php`, `ProblemCategoryResource.php`, `TicketStatusResource.php`, `BusinessEntityResource.php`, models, policies, and seeders.

## Flow

- Trigger: Authorized admin opens a master data or user resource.
- Preconditions: User has corresponding Filament Shield permission.
- Main path:
  1. Admin lists records.
  2. Admin creates or edits supported fields.
  3. Admin may soft-delete, restore, or force-delete where resource exposes actions.
  4. System applies policy checks and resource query scoping.
- Exceptions: Missing permissions block actions.
- Postconditions: Master data or user records are updated for use by tickets and APIs.

## Data Used

ENT-001 User, ENT-003 Unit, ENT-004 ProblemCategory, ENT-006 TicketStatus, ENT-007 BusinessEntity, ENT-011 UserEntity.

## Acceptance Criteria

- AC-UC-008-01: Users can be assigned units and active state.
- AC-UC-008-02: Units and categories can be managed.
- AC-UC-008-03: Business entities can be managed.
- AC-UC-008-04: Ticket statuses can be viewed with ticket counts.

