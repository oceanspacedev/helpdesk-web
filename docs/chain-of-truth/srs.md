# Software Requirements Specification As-Is

Status: Reviewed  
Source type: Reconstructed from implementation and README. Business intent remains unvalidated.

## Problem Statement

Observed: The system provides a web-based helpdesk where users can report issues, staff can manage tickets, and ITA/WhatsApp can create or interact with tickets through APIs.  
Inferred: The organization needs central ticket tracking across units, business entities, categories, priorities, status history, comments, and WhatsApp-assisted access.

## Actors

| ID | Actor | Evidence label | Description |
|---|---|---|---|
| ACT-001 | Super Admin | Observed | Full administrative role seeded and referenced in policies/resources. |
| ACT-002 | Admin Unit | Observed | Unit-scoped administrator with ticket visibility and workflow actions. |
| ACT-003 | Staff Unit | Conflict | Seeded as `Staff Unit`; some code checks `Staf Unit`. Intended support staff role is inferred. |
| ACT-004 | General User | Observed | Ticket reporter account in README and owner-based ticket access in policy. |
| ACT-005 | ITA WhatsApp Actor | Observed | Verified external actor payload used by integration API. |
| ACT-006 | Existing Socialite User | Observed | Helpdesk user who can link provider identity when email matches. |

## Functional Requirements

| ID | Requirement | Label | Evidence |
|---|---|---|---|
| FR-001 | The system shall redirect `/` to the admin panel. | Observed | `routes/web.php`, `ExampleTest.php` |
| FR-002 | The system shall allow active users to access the Filament admin panel. | Observed | `User::canAccessPanel()` |
| FR-003 | The system shall support ticket creation with unit, problem category, title, description, supporting attachments, priority, and business entity. | Observed | `TicketResource::form()`, `CreateTicket.php`, migrations |
| FR-004 | The system shall set ticket owner to the authenticated user and initial status to Open on web ticket creation. | Observed | `CreateTicket::mutateFormDataBeforeCreate()` |
| FR-005 | The system shall list, filter, view, edit, export, delete, restore, and force-delete tickets according to resource actions and policies. | Observed | `TicketResource.php`, `ListTickets.php`, `TicketPolicy.php` |
| FR-006 | The system shall restrict ticket visibility by role and ownership. | Observed with conflict | `TicketResource::getEloquentQuery()`, `TicketPolicy.php` |
| FR-007 | The system shall allow Admin Unit or Super Admin to process, cancel, or complete eligible tickets from ticket view. | Observed | `ViewTicket.php` |
| FR-008 | The system shall create ticket history records when tickets are created or updated. | Observed | `Ticket::boot()` |
| FR-009 | The system shall set `approved_at` when ticket status leaves Open and `solved_at` when status becomes Closed. | Observed | `Ticket::saving()` |
| FR-010 | The system shall notify responsible users or unit users when tickets and comments are created, and notify owner when tickets close. | Observed | `Ticket.php`, `Comment.php`, notification classes |
| FR-011 | The system shall support comments with rich text and optional attachments on tickets. | Observed | `CommentsRelationManager.php`, `comments` migration |
| FR-012 | The system shall expose master-data options for WhatsApp/ITA helpdesk forms. | Observed | `WhatsappHelpdeskMasterDataController.php`, tests |
| FR-013 | The system shall validate WhatsApp/ITA classification fields against business entities, units, problem categories, and priorities. | Observed | `WhatsappHelpdeskValidateClassificationController.php`, resolver |
| FR-014 | The system shall allow verified ITA actors to create tickets through the WhatsApp integration API. | Observed | `WhatsappHelpdeskActionService.php`, tests |
| FR-015 | The system shall auto-register a verified phone actor without email for ITA ticket creation when no active user exists. | Observed | `createAutoRegisteredReporter()`, email nullable migration, tests |
| FR-016 | The system shall reject ITA actors that are unverified, unknown for non-create actions, or identified only by LID where phone cannot be resolved. | Observed | action service and tests |
| FR-017 | The system shall support ITA ticket lookup and comment addition for authorized actors. | Observed | action service and tests |
| FR-018 | The system shall reject unsupported ITA actions such as close-ticket action. | Observed | action service and tests |
| FR-019 | The system shall implement idempotency for successful ITA actions with a configurable TTL. | Observed | action service and tests |
| FR-020 | The system shall support phone-number OTP login through WhatsApp. | Observed | `PhoneLogin.php`, `PhoneOtpLoginController.php`, tests |
| FR-021 | The system shall support Socialite login linking for existing helpdesk users while registration can remain disabled. | Observed | `SocialiteController.php`, tests |
| FR-022 | The system shall manage users, units, problem categories, ticket statuses, and business entities through Filament resources. | Observed | resource classes |

## Non-Functional Requirements

| ID | Requirement | Label | Evidence |
|---|---|---|---|
| NFR-001 | The application shall run on PHP 8.2 or higher. | Observed | `composer.json`, README |
| NFR-002 | The web admin shall use Laravel 12 and Filament 4. | Observed | `composer.json`, README |
| NFR-003 | WhatsApp phone normalization shall support Indonesian local numbers. | Observed | `WhatsAppGatewayTest.php`, phone traits |
| NFR-004 | ITA method errors shall avoid exposing Laravel debug details. | Observed | `WhatsappHelpdeskActionTest.php` |
| NFR-005 | OTP codes shall be six digits and expire based on configured TTL. | Observed | `PhoneLogin.php`, `PhoneOtpLoginController.php` |
| NFR-006 | Attachments for web tickets shall be limited to defined file types, maximum five files, and total web upload rule of 10 MB. | Observed | `TicketResource::form()` |
| NFR-007 | Outbound WhatsApp gateway shall support provider fallback when enabled. | Observed | `WhatsAppGateway.php`, `WhatsAppGatewayTest.php` |

## Business Rules

| ID | Rule | Label |
|---|---|---|
| BR-001 | New tickets start in Open status. | Observed |
| BR-002 | Ticket status IDs are Open `1`, In Progress `2`, Cancel `3`, Closed `4`. | Observed |
| BR-003 | Closed tickets receive `solved_at`; non-Open tickets receive `approved_at` if missing. | Observed |
| BR-004 | A ticket owner cannot update a ticket after it leaves Open. | Observed |
| BR-005 | Cancelled or closed tickets cannot be updated through policy. | Observed |
| BR-006 | ITA create-ticket requires issue summary, affected system, impact, consent, business entity, unit, category, and priority. | Observed |
| BR-007 | ITA phone-only reporters may have null email and null password. | Observed |
| BR-008 | Socialite auto-registration may be disabled; existing users can still link provider identities. | Observed |

## Constraints And Open Questions

- Unknown: Whether WhatsApp integration endpoints must be protected by API keys, signatures, network ACLs, or another upstream control.
- Unknown: Whether role name should be `Staff Unit` or `Staf Unit`.
- Unknown: Whether direct assignment rules for specific problem category IDs are intentional and current.
- Unknown: Whether `unit_id` on users is still authoritative now that `user_entities` provides many-to-many unit membership.
- Unknown: Production operational requirements for queues, notifications, storage, logging, retention, and backups.

