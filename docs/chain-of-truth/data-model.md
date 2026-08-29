# Data Model As-Is

Status: Reviewed  
Source type: Reconstructed from migrations, models, and seeders.

## Entities

| ID | Entity | Table | Key fields | Relationships |
|---|---|---|---|---|
| ENT-001 | User | `users` | `id`, `unit_id`, `name`, nullable unique `email`, `email_verified_at`, `email_verified_via`, nullable `password`, `identity`, `phone`, unique `phone_normalized`, `is_active` | Has roles, tickets as owner, comments, socialite users, morph units through `user_entities` |
| ENT-002 | Ticket | `tickets` | `priority_id`, destination `unit_id`, sender `owner_id`, `problem_category_id`, `title`, `description`, `supporting_attachments`, `ticket_statuses_id`, processing agent `responsible_id`, `business_entities_id`, dates | Belongs to priority, destination unit, sender/owner, responsible agent, category, status, business entity; has comments and histories |
| ENT-003 | Unit | `units` | `name` | Has categories, tickets; morph-to-many users |
| ENT-004 | ProblemCategory | `problem_categories` | `unit_id`, `name` | Belongs to unit; has tickets |
| ENT-005 | Priority | `priorities` | `name` | Has tickets |
| ENT-006 | TicketStatus | `ticket_statuses` | `name` | Has tickets |
| ENT-007 | BusinessEntity | `business_entities` | `name` | Has tickets |
| ENT-008 | Comment | `comments` | `tiket_id`, `user_id`, `comment`, `attachments` fillable | Belongs to ticket and user |
| ENT-009 | TicketHistory | `ticket_histories` | `ticket_id`, `ticket_statuses_id`, `user_id` | Belongs to user, ticket, status |
| ENT-010 | SocialiteUser | `socialite_users` | `user_id`, `provider`, `provider_id` | Belongs to user |
| ENT-011 | UserEntity | `user_entities` | `user_id`, `entity_id`, `entity_type` | Polymorphic bridge for user-to-entity relations |
| ENT-012 | HelpdeskMcpSetting | `helpdesk_mcp_settings` | singleton `id=1`, encrypted token list, intake TTL, request limit, encrypted identity pepper/assertion secret, assertion leeway | Server-wide configuration consumed by HTTP MCP authentication, intake state, throttling, and trusted identity verification |

## Lifecycle Rules

- ENT-002 Ticket is soft-deletable.
- A soft-deleted ticket remains viewable to authorized mailbox participants for recovery/audit, but report editing, workflow transitions, and comment mutations are read-only until restoration.
- ENT-003 Unit, ENT-004 ProblemCategory, ENT-006 TicketStatus, ENT-007 BusinessEntity are soft-deletable in models or migrations.
- ENT-001 User is soft-deletable and must be active plus authenticated through trusted verified email or the current session's matching phone-OTP proof to access Filament.
- ENT-008 Comment is soft-deletable.
- ENT-002 status changes create ENT-009 TicketHistory.
- ENT-002 uses one record for both mailbox views: `owner_id` places it in the sender's Ticket Keluar, while `unit_id` places it in the destination unit's Ticket Masuk. No duplicated inbox/outbox rows are stored.
- Admin Unit and Staff Unit may process only tickets addressed to one of their assigned units. Processing is separate from editing report content. Once In Progress, only an eligible `responsible_id` (or a global administrator) may transition it. If that responsible user becomes inactive, loses permission, or leaves the destination unit, another eligible destination processor may take over. Cancel and Closed are terminal for unit processors.
- ENT-012 has at most one application-owned row. Its token and secret fields are encrypted with the shared `APP_KEY`; no secret is seeded by migration, and environment values remain rollout fallbacks until the row is saved.

## Field Constraints

- Ticket title max length is 255 in form.
- Ticket description is required and max length 65535 in form.
- Ticket supporting attachments are JSON and nullable.
- User phone is canonicalized on every model save and protected by a database unique constraint on `phone_normalized`; the constraint migration stops until legacy duplicates are repaired.
- Email is usable for password authentication and mail notifications only when `email_verified_at` and trusted `email_verified_via` provenance are both present. Legacy timestamps are marked `legacy_review_required`.
- Comment table uses `tiket_id`, preserving current schema spelling.
- The MCP settings UI accepts intake TTL from 10 to 1,440 minutes, request limits from 60 to 600 per token per minute, and trusted assertion leeway from 30 to 3,600 seconds. `HELPDESK_MCP_LOCAL_CLIENT_ID` is intentionally client-local and is not stored in ENT-012.

## Data Model Gaps

- `user_entities` is authoritative for user-to-unit access. Migration `2026_08_29_000003` backfills users that have no unit pivot from legacy `users.unit_id`; the legacy column is used only in environments where the pivot table is unavailable. Problem-category administration uses the same authoritative membership.
- `Staff Unit` is the canonical staff role. Migration `2026_08_29_000004` merges any legacy `Staf Unit` memberships and permissions without discarding existing canonical assignments.
- `TicketHistory::ticket()` uses `tiket_id` relationship key while table field is `ticket_id`.
- `Comment::$fillable` includes `attachments`, but base migration excerpt does not create an `attachments` column; attachment migration for comments should be confirmed.
- Some models set `$timestamps = false` while migrations include timestamps.
