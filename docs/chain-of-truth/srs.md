# Software Requirements Specification As-Is

Status: Reviewed  
Source type: Reconstructed from implementation and README. Business intent remains unvalidated.

## Problem Statement

Observed: The system provides a web-based helpdesk where staff manage tickets and one vendor-neutral MCP intake lets verified reporters create tickets without opening or signing in to the web form. The MCP contract has exactly two business paths: an existing eligible account proceeds to ticket creation, while a missing account is created as a prerequisite before the same intake proceeds to ticket creation.

Inferred: The organization needs central ticket tracking across units, business entities, categories, priorities, status history, comments, and WhatsApp-assisted identity verification.

## Actors

| ID | Actor | Evidence label | Description |
|---|---|---|---|
| ACT-001 | Super Admin | Observed | Full administrative role seeded and referenced in policies/resources. |
| ACT-002 | Admin Unit | Observed | Unit-scoped administrator with ticket visibility and workflow actions. |
| ACT-003 | Staff Unit | Observed | Canonical support-staff role; may create outgoing tickets and process tickets received by an assigned unit. |
| ACT-004 | General User | Observed | Ticket reporter account in README and owner-based ticket access in policy. |
| ACT-005 | MCP Reporter | Observed | Reporter using Codex, Atlas relaying WhatsApp, another AI host, or another channel bridge as a generic client of the same MCP contract. |
| ACT-006 | Trusted Existing Socialite User | Observed | Active, non-deleted Helpdesk user who can link provider identity only through an email with trusted verification provenance. |

## Functional Requirements

| ID | Requirement | Label | Evidence |
|---|---|---|---|
| FR-001 | The system shall redirect `/` to the admin panel. | Observed | `routes/web.php`, `ExampleTest.php` |
| FR-002 | The system shall allow an active user to access the Filament panel only through trusted verified email authentication or the current session's matching phone-OTP proof. | Observed | `User::canAccessPanel()` |
| FR-003 | The system shall support ticket creation with unit, problem category, title, description, supporting attachments, priority, and business entity. | Observed | `TicketResource::form()`, `CreateTicket.php`, migrations |
| FR-004 | The system shall set ticket owner to the authenticated user and initial status to Open on web ticket creation. | Observed | `CreateTicket::mutateFormDataBeforeCreate()` |
| FR-005 | The system shall list, filter, view, edit, export, delete, restore, and force-delete tickets according to resource actions and policies. | Observed | `TicketResource.php`, `ListTickets.php`, `TicketPolicy.php` |
| FR-006 | The system shall show a ticket in the sender's personal Ticket Keluar and in the destination unit's Ticket Masuk, while denying unrelated units. | Observed | ticket mailbox scopes, `TicketResource::getEloquentQuery()`, `TicketPolicy.php` |
| FR-007 | The system shall allow Admin Unit and Staff Unit members of the destination unit (or global administrators) to claim and process eligible tickets, allow takeover when the previous responsible user is no longer eligible, and prevent the sending unit from processing a cross-unit outgoing ticket. | Observed | `ViewTicket.php`, `TicketPolicy.php` |
| FR-008 | The system shall create ticket history records when tickets are created or updated. | Observed | `Ticket::boot()` |
| FR-009 | The system shall set `approved_at` when ticket status leaves Open and `solved_at` when status becomes Closed. | Observed | `Ticket::saving()` |
| FR-010 | The system shall notify an eligible responsible user or active destination processors when tickets and comments are created, fall back when a responsible user is no longer eligible, and notify the owner after a ticket is successfully closed. | Observed | `Ticket.php`, `Comment.php`, notification classes |
| FR-011 | The system shall support comments with rich text and optional attachments on tickets. | Observed | `CommentsRelationManager.php`, `comments` migration |
| FR-012 | The MCP intake shall provide current business-entity, unit, problem-category, and priority options when a classification answer is missing or invalid. | Observed | `HelpdeskFormOptionsService.php`, MCP intake tests |
| FR-013 | The MCP intake shall validate classification answers against active business entities, units, problem categories, and priorities before ticket creation. | Observed | `HelpdeskClassificationResolver.php`, MCP intake tests |
| FR-014 | The system shall allow a verified MCP reporter to create a ticket only after resolving one canonical reporter identity and an eligible Helpdesk account, and shall reject creation if the resolved Helpdesk user or Talenta fingerprint changes before commit. | Observed | `HelpdeskIntakeSession.php`, `HelpdeskTicketCreationService.php`, tests |
| FR-015 | The MCP shall expose exactly two business paths through `helpdesk_intake`: reuse an existing eligible reporter account and create its ticket, or create the required reporter account first and then create its ticket in the same intake. An exact Talenta match may supply the account data server-side; a reporter absent from both directories requires explicit consent and a full name typed at the dedicated account-creation step. Account creation is not a standalone MCP feature. | Observed | reporter registration and ticket-creation services, tests |
| FR-016 | The MCP intake shall reject unverified identities, conflicting phone claims, ambiguous Helpdesk or Talenta matches, and LID-only identities whose phone cannot be resolved. A verified reporter absent from both directories shall receive `registration_consent`, then `registration_name` only after explicit acceptance; declining shall return terminal `cancelled` and create neither an account nor a ticket. | Observed | MCP intake and identity services, tests |
| FR-019 | MCP intake replay and final ticket creation shall be idempotent per authenticated client and request identity; an exact retry replays the stored result, while reuse with different content is rejected. | Observed | MCP intake, durable ticket-creation request storage, tests |
| FR-020 | The system shall support phone-number OTP login and account creation through WhatsApp. Helpdesk/Talenta lookup and any manual or Talenta-backed user creation shall occur only after phone ownership is proven. Verified unknown web users may complete a session-and-phone-bound name step without a second OTP. The separate MCP account-prerequisite path additionally requires explicit consent and a freshly typed full name; it shall atomically recheck Helpdesk/Talenta and create a phone-only user with null email and password before continuing the same intake to ticket creation. Abandoned, declined, failed, expired, tampered, ambiguous, or changed-directory attempts shall not create an account. | Observed | `PhoneLogin.php`, MCP intake and registration services, `WhatsAppOtpService.php`, tests |
| FR-021 | The system shall never auto-register an unknown Socialite identity and shall link or reuse Socialite only for an active, non-deleted Helpdesk user whose email has trusted verification provenance. | Observed | `SocialiteController.php`, tests |
| FR-022 | The system shall manage users, units, problem categories, ticket statuses, and business entities through Filament resources. | Observed | resource classes |

## Non-Functional Requirements

| ID | Requirement | Label | Evidence |
|---|---|---|---|
| NFR-001 | The application shall run on PHP 8.2 or higher. | Observed | `composer.json`, README |
| NFR-002 | The web admin shall use Laravel 12 and Filament 4. | Observed | `composer.json`, README |
| NFR-003 | WhatsApp phone normalization shall support Indonesian local numbers. | Observed | `WhatsAppGatewayTest.php`, phone traits |
| NFR-004 | MCP transport and tool errors shall avoid exposing Laravel debug details. | Observed | MCP HTTP feature tests |
| NFR-005 | OTP codes shall be six digits, expire based on configured TTL, and be single-use. | Observed | `PhoneLogin.php`, `WhatsAppOtpService.php` |
| NFR-006 | Attachments for web tickets shall be limited to defined file types, maximum five files, and total web upload rule of 10 MB. | Observed | `TicketResource::form()` |
| NFR-007 | Outbound WhatsApp gateway shall support provider fallback when enabled. | Observed | `WhatsAppGateway.php`, `WhatsAppGatewayTest.php` |
| NFR-008 | All identity boundaries shall compare the uniquely indexed canonical phone, include inactive and soft-deleted accounts when detecting collisions, reject ambiguous legacy data, keep pre-OTP directory state private, and ensure the reporter resolved during intake is still the owner at ticket commit. | Observed | `PhoneNumber.php`, user migrations, login and integration services, tests |
| NFR-009 | Durable MCP reporter bindings shall require a stable caller-supplied `external_user_id`; local MCP calls without one shall not reuse a binding and shall verify the phone on every new intake. | Observed | MCP intake service and tests |

## Business Rules

| ID | Rule | Label |
|---|---|---|
| BR-001 | New tickets start in Open status. | Observed |
| BR-002 | Ticket status IDs are Open `1`, In Progress `2`, Cancel `3`, Closed `4`. | Observed |
| BR-003 | Closed tickets receive `solved_at`; non-Open tickets receive `approved_at` if missing. | Observed |
| BR-004 | A ticket owner cannot update a ticket after it leaves Open. | Observed |
| BR-005 | Cancelled, closed, or soft-deleted tickets cannot run workflow updates; soft-deleted tickets and their comments remain read-only until restoration. | Observed |
| BR-006 | MCP exposes only `helpdesk_intake` for the two ticket-reporting paths. Ticket creation requires issue details, explicit creation confirmation, business entity, unit, category, and priority; ticket lookup, comments, updates, workflow actions, administration, and standalone account management are outside the MCP contract. | Observed |
| BR-007 | A reporter provisioned from exactly one Talenta phone match or created through consent-gated MCP inline registration may have null email and password. Inline registration must use the name typed at `registration_name`, never `reporter_name`. | Observed |
| BR-008 | Socialite never auto-registers an unknown Helpdesk account; it may link only one active user whose email already has trusted verification provenance. | Observed |
| BR-009 | MCP identity resolution shall use one canonical verified phone. Conflicting phone claims are rejected. An unknown verified phone may create its required account only through consent-gated, atomic inline account creation; declining cancels the intake without writes, a web URL, or a redirect. | Observed |
| BR-010 | A WhatsApp OTP proves control of a phone only. It does not verify an email address. Unknown manual reporters are registered as phone-only users; password login and mail notifications require both an email timestamp and trusted `email_verified_via` provenance. Legacy timestamps are quarantined for review. | Observed |
| BR-011 | `tickets.owner_id` is the sender, `tickets.unit_id` is the destination, and `tickets.responsible_id` is the processing agent. A unit processor may process only incoming tickets; an In Progress ticket belongs to its eligible responsible agent and becomes available for takeover if that agent loses eligibility. | Observed |

## Constraints And Open Questions

- Unknown: Production topology and credential-rotation requirements for the HTTP and local MCP transports.
- Unknown: Whether direct assignment rules for specific problem category IDs are intentional and current.
- Known compatibility rule: `user_entities` is authoritative for unit membership after legacy `users.unit_id` values are backfilled for users without an existing unit pivot.
- Unknown: Production operational requirements for queues, notifications, storage, logging, retention, and backups.
