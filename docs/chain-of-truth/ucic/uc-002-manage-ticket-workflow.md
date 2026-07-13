# UCIC UC-002 Manage Ticket Workflow

Status: Reviewed

## Contract

- Interface: Filament `ViewTicket` page actions and `TicketPolicy`.
- Authentication: Filament authenticated user.
- Authorization: Admin Unit or Super Admin for page actions; policy also governs update.
- Inputs: Action `cancel`, `proses`, or `selesai`.
- State mapping: Cancel sets status `3`; Proses sets status `2`; Selesai sets status `4`; all set `responsible_id` to current user.
- Side effects: `approved_at` set when leaving Open, `solved_at` set on Closed, history created on update, closed notification sent to owner.
- Error cases: Unavailable UI action, policy denies update for Closed/Cancel or owner after Open.

