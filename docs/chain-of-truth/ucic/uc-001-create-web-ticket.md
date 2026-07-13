# UCIC UC-001 Create Web Ticket

Status: Reviewed

## Contract

- Interface: Filament `TicketResource` create page.
- Authentication: Filament authenticated active user.
- Request fields: `unit_id`, `problem_category_id`, `title`, `description`, `supporting_attachments`, `priority_id`, `business_entities_id`.
- Server additions: `owner_id = auth()->id()`, `ticket_statuses_id = TicketStatus::OPEN`.
- Side effects: Ticket history creation, notifications to responsible or unit users.
- Result: Ticket record persisted and visible through ticket resource according to policy.
- Error cases: Form validation failures; policy denial.
- Idempotency: Not implemented for web create.

