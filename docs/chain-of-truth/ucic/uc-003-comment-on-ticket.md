# UCIC UC-003 Comment On Ticket

Status: Reviewed

## Contract

- Interface: Filament `CommentsRelationManager`.
- Authentication: Filament authenticated user.
- Request fields: `comment`, optional `attachments`.
- Server additions: `user_id = auth()->id()`, relation sets `tiket_id`.
- Result: Comment row created and displayed in ticket relation table.
- Side effects: Comment notification dispatch.
- Error cases: Required comment missing; attachment download action hidden when no attachment path exists.

