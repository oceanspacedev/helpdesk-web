# UCIC UC-004 ITA Create Ticket

Status: Reviewed

## Contract

- Interfaces: `GET API-001`, `POST API-002`, `POST API-003`.
- Authentication: No route middleware observed; payload-level `actor.is_verified` is required by action service.
- Supported action: `helpdesk.create_ticket`.
- Required payload fields: `action`, `actor`, `ticket_data.issue_summary`, `ticket_data.affected_system`, `ticket_data.impact`, `ticket_data.consent_to_create`, classification fields for business entity, unit, problem category, and priority.
- Optional fields: `request_id`, `idempotency_key`, `attachments`, `media_errors`, location/start/error/requested outcome metadata.
- Success response: HTTP 200, `ok: true`, `result_status: ticket_created`, `data.ticket`, optional `data.idempotency`.
- Validation response: HTTP 422, `ok: false`, `result_status: validation_error`, `data.missing_fields`, `data.form_options`, `error.code`.
- Forbidden response: HTTP 403 for unverified or unresolved actor.
- Side effects: Optional auto-registration of phone-only user, ticket creation, history creation, notifications, idempotency cache.
- Idempotency: Successful responses are cached by SHA1 of idempotency key for configured TTL.

