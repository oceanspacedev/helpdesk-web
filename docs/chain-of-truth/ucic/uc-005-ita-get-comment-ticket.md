# UCIC UC-005 ITA Get Or Comment Ticket

Status: Reviewed

## Contract

- Interface: `POST API-003`.
- Supported actions: `helpdesk.get_ticket`, `helpdesk.add_comment`.
- Authentication: Payload-level verified actor and active helpdesk user resolution.
- Authorization: Actor can access ticket if owner, responsible user, or has any role among Super Admin, Admin Unit, Staff Unit, Staf Unit.
- Ticket reference: `ticket_ref.ticket_id`, `ticket_ref.id`, top-level `ticket_id`, or parseable ticket number.
- Comment input: `ticket_data.description` or `message.text`, with optional attachment links appended.
- Success responses: `found` or `comment_added`.
- Error responses: 404 not found, 403 forbidden, 422 validation or unsupported action.
- Side effects: Get has no state change; comment creates ENT-008.
- Idempotency: Same as UC-004 for successful keyed calls.

