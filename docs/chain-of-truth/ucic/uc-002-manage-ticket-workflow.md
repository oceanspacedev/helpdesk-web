# UCIC UC-002 Manage Ticket Workflow

Status: Reviewed

## Contract

- Interface: Filament `ViewTicket` page actions and `TicketPolicy`.
- Authentication: Filament authenticated user.
- Authorization: Admin Unit and Staff Unit may process tickets for assigned destination units; Super Admin and Master Admin are global processors. Report editing and workflow processing use separate policy abilities.
- Inputs: Action `cancel`, `proses`, `ambil_alih`, or `selesai`.
- State mapping: Cancel sets status `3`; Proses and Ambil Alih set or retain status `2`; Selesai sets status `4`; all set `responsible_id` to current user.
- Side effects: `approved_at` set when leaving Open, `solved_at` set on Closed, history created on update, closed notification sent to owner.
- Error cases: Unavailable/stale UI action, another processor winning the row lock, or the process policy denying an unrelated unit, terminal ticket, or non-responsible processor while the current responsible remains eligible.
