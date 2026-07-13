# Data Model As-Is

Status: Reviewed  
Source type: Reconstructed from migrations, models, and seeders.

## Entities

| ID | Entity | Table | Key fields | Relationships |
|---|---|---|---|---|
| ENT-001 | User | `users` | `id`, `unit_id`, `name`, nullable unique `email`, nullable `password`, `identity`, `phone`, `is_active` | Has roles, tickets as owner, comments, socialite users, morph units through `user_entities` |
| ENT-002 | Ticket | `tickets` | `priority_id`, `unit_id`, `owner_id`, `problem_category_id`, `title`, `description`, `supporting_attachments`, `ticket_statuses_id`, `responsible_id`, `business_entities_id`, dates | Belongs to priority, unit, owner, responsible, category, status, business entity; has comments and histories |
| ENT-003 | Unit | `units` | `name` | Has categories, tickets; morph-to-many users |
| ENT-004 | ProblemCategory | `problem_categories` | `unit_id`, `name` | Belongs to unit; has tickets |
| ENT-005 | Priority | `priorities` | `name` | Has tickets |
| ENT-006 | TicketStatus | `ticket_statuses` | `name` | Has tickets |
| ENT-007 | BusinessEntity | `business_entities` | `name` | Has tickets |
| ENT-008 | Comment | `comments` | `tiket_id`, `user_id`, `comment`, `attachments` fillable | Belongs to ticket and user |
| ENT-009 | TicketHistory | `ticket_histories` | `ticket_id`, `ticket_statuses_id`, `user_id` | Belongs to user, ticket, status |
| ENT-010 | SocialiteUser | `socialite_users` | `user_id`, `provider`, `provider_id` | Belongs to user |
| ENT-011 | UserEntity | `user_entities` | `user_id`, `entity_id`, `entity_type` | Polymorphic bridge for user-to-entity relations |

## Lifecycle Rules

- ENT-002 Ticket is soft-deletable.
- ENT-003 Unit, ENT-004 ProblemCategory, ENT-006 TicketStatus, ENT-007 BusinessEntity are soft-deletable in models or migrations.
- ENT-001 User is soft-deletable and must be active to access Filament.
- ENT-008 Comment is soft-deletable.
- ENT-002 status changes create ENT-009 TicketHistory.

## Field Constraints

- Ticket title max length is 255 in form.
- Ticket description is required and max length 65535 in form.
- Ticket supporting attachments are JSON and nullable.
- User phone is normalized in forms and OTP flows; test schemas make phone unique but production migration uniqueness depends on observed migrations not fully enumerated in this document.
- Comment table uses `tiket_id`, preserving current schema spelling.

## Data Model Gaps

- `users.unit_id` and `user_entities` both model user-unit affiliation; authoritative source is not clear.
- `TicketHistory::ticket()` uses `tiket_id` relationship key while table field is `ticket_id`.
- `Comment::$fillable` includes `attachments`, but base migration excerpt does not create an `attachments` column; attachment migration for comments should be confirmed.
- Some models set `$timestamps = false` while migrations include timestamps.

