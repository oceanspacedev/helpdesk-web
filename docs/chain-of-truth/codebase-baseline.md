# Codebase Baseline

Status: Reviewed  
Mode: Brownfield documentation and evaluation  
Evidence date: 2026-08-28
Method note: This documentation uses the Chain of Truth method by Farid Suryanto and Muhammad Ibnu Athoillah. References: https://faridsurya-dev.github.io/Vibe-Coding-Research/welcome, https://faridsurya-dev.github.io/Vibe-Coding-Research/en/1-concept/what-is-chain-of-truth, https://github.com/faridsurya-dev/vibe_coding_simple_case, https://doi.org/10.5281/zenodo.20767965.

## Executive Outcome

The repository implements a Laravel 12 and Filament 4 web helpdesk with ticket management, master data administration, role-based visibility, WhatsApp OTP login, Socialite login linking, WhatsApp gateway sending, and deterministic ticket creation through one vendor-neutral MCP tool. MCP has exactly two business paths: an existing eligible account proceeds to ticket creation, or a missing account is created as a prerequisite before the same intake creates the ticket. Confidence is medium-high for implementation facts because they are observed in source, routes, migrations, seeders, and tests. Confidence is medium for business intent because most requirements are reconstructed from implementation rather than stakeholder-approved artifacts.

## Scope

- Observed: `/` redirects to the Filament admin panel at `/admin`.
- Observed: Filament resources exist for Ticket, User, Unit, Problem Category, Ticket Status, and Business Entity.
- Observed: `routes/ai.php` exposes the `helpdesk` MCP server over token-protected HTTP at `/mcp/helpdesk` and as a local MCP transport.
- Observed: web routes expose the Livewire `/phone-login` flow and Socialite provider redirects/callbacks. OTP verification is a server-side Livewire action, not a separate public form route.
- Inferred: The product supports internal Complete Selular helpdesk operations and MCP-assisted ticket reporting across generic clients such as Codex, Atlas relaying WhatsApp, other AI hosts, and other channel bridges. No client receives a vendor-specific server path.

## Runtime And Dependencies

- Observed: PHP requirement is `^8.2`.
- Observed: `laravel/framework` is `^12.61`, `filament/filament` and `filament/infolists` are `^4.11`.
- Observed: Key packages include Filament Shield, Filament Breezy, Filament Socialite, Laravel Sanctum, Laravel Socialite, Guzzle, Pusher, Filament Excel, Apex Charts, Filament Exceptions, Overlook, and a Mekaya theme repository.
- Observed: Node frontend tooling uses Vite, Tailwind CSS 4, Axios, and Laravel Vite plugin.
- Unknown: Production database, queue, cache, mail, storage, and deployment topology are not documented in repository docs.

## Entry Points

- Observed: `routes/web.php` defines `/`, `/phone-login`, `/auth/{provider}`, and `/auth/{provider}/callback`.
- Observed: `routes/api.php` defines authenticated `/api/user`; `routes/ai.php` registers the HTTP and local MCP transports for Helpdesk intake.
- Observed: `app/Providers/Filament/AdminPanelProvider.php` configures panel id `admin`, path `admin`, brand `Helpdesk`, Filament login, plugins, resources, pages, widgets, and theme.

## Module Map

| Module | Observed elements | Responsibility |
|---|---|---|
| Admin panel | `AdminPanelProvider`, Filament resources and pages | Web UI, resource CRUD, dashboard widgets |
| Tickets | `TicketResource`, `Ticket`, `TicketPolicy`, ticket pages, relation managers | Ticket lifecycle, comments, history, export |
| Master data | Unit, ProblemCategory, Priority, TicketStatus, BusinessEntity resources and models | Selection lists and operational classification |
| Users and access | User resource, role relations, policies, Filament Shield, Breezy, Socialite | User management, permissions, login/profile |
| Phone OTP | `PhoneLogin`, `WhatsAppOtpService`, `WhatsAppGateway` | WhatsApp OTP delivery, deferred registration, and phone-based login |
| MCP Helpdesk intake | `HelpdeskServer`, `HelpdeskIntakeTool`, intake and ticket-creation services | One tool with two paths: verify and reuse an eligible account for ticket creation, or create the required account before the same intake creates the ticket |
| Notifications | Ticket and comment model events, notification classes | New ticket, comment, closed ticket notifications |
| Tests | PHPUnit feature and unit tests | Regression coverage for current critical integrations |

## Actors And Permissions

- Observed: Seeded roles are `Super Admin`, `Admin Unit`, and `Staff Unit`.
- Observed: README also lists dummy accounts for Super Admin, Admin Unit, Staff Unit, and General User.
- Observed: Several code paths check `Staf Unit` while seeders create `Staff Unit`, creating a role-name conflict.
- Observed: `User::canAccessPanel()` requires an active user plus either trusted email provenance or the current phone-verified session.
- Observed: `TicketPolicy` allows Admin Unit to view owned tickets or tickets in the user's units, Staff Unit to view owned or assigned tickets, and regular users to view owned tickets.
- Inferred: A regular user is represented by the absence of admin/staff role or a `User` role assigned elsewhere.

## Critical Journeys

- UC-001: Authenticated users create tickets through Filament.
- UC-002: Admin Unit or Super Admin processes, cancels, or completes open/in-progress tickets from the ticket view page.
- UC-003: Participants add comments to tickets in the ticket relation manager and notifications are sent.
- UC-004: MCP reporters create tickets through exactly two business paths after their canonical phone resolves safely. An existing eligible Helpdesk account proceeds to ticket creation. If that account is missing, the server creates the prerequisite account—using an exact Talenta match when available or explicit consent plus a freshly typed name otherwise—and continues the same intake to ticket creation. Declining account creation cancels the intake without writes. Conflicting phone claims are rejected.
- UC-006: Users log in or register with a phone number OTP sent through WhatsApp. New manual or Talenta-backed accounts remain encrypted pending data until OTP succeeds, preventing active credentials from being created for an unverified number.
- UC-007: Existing active Helpdesk users with trusted email provenance may link Socialite identities; unknown Socialite identities never auto-register.
- UC-008: Admin users manage master data and users through Filament resources.

## Data Stores And Entities

- Observed: Relational tables include `users`, `tickets`, `comments`, `ticket_histories`, `units`, `problem_categories`, `priorities`, `ticket_statuses`, `business_entities`, `user_entities`, `socialite_users`, permission tables, notifications, activity log, failed jobs, and personal access tokens.
- Observed: Ticket soft deletes are enabled and ticket status dates include `approved_at` and `solved_at`.
- Observed: A historical nullable-email migration supports Talenta-backed and consent-gated MCP phone-only reporters. Its deployment-ledger filename remains unchanged as migration history and is not part of the active integration contract.
- Observed: Comments use column `tiket_id`, not `ticket_id`.
- Observed: `supporting_attachments` is a nullable JSON column on tickets.

## External Interfaces

- Observed: WhatsApp outbound gateway supports WAHA and Fonnte providers through Guzzle and config-driven credentials.
- Observed: MCP intake verifies a single canonical reporter phone before directory resolution. Conflicting claims are rejected. An existing eligible Helpdesk account proceeds to ticket creation. If no eligible account exists, exactly one Talenta match may supply the prerequisite account data; a reporter absent from both directories must explicitly consent and type a fresh full name before atomic phone-only account creation continues the same intake. Declining returns terminal cancellation without an account or ticket write.
- Observed: Socialite routes call `Socialite::driver($provider)` and link only active users whose Helpdesk email has trusted verification provenance; provider auto-registration remains disabled.
- Observed: MCP over HTTP uses its own bearer-token middleware. A trusted WhatsApp gateway may inject a one-event identity assertion, but that assertion does not authorize the MCP client transport. Atlas, Codex, and channel bridges remain generic MCP clients of this same contract.

## Operational Topology

- Observed: README installation steps include Composer install, `.env`, key generation, migration, seeding, storage link, and `php artisan serve`.
- Observed: `package.json` supports `npm run dev` and `npm run build`.
- Unknown: CI/CD, queue workers, scheduled jobs, production runbook, log aggregation, and alerting are not documented.

## Test Coverage

- Observed: Feature tests cover root redirect, disabled admin registration, strict Socialite linking, panel/profile credential gates, deferred phone registration, identity migrations, MCP HTTP/local contracts, isolated external users, retry/idempotency, ticket creation, and chaos evaluation of the intake trigger.
- Observed: Unit tests cover employee matching/provisioning, phone canonicalization, WhatsApp OTP isolation/rate limits/locks, and WhatsApp gateway behavior.
- Gap: There is no observed browser/E2E coverage for Filament ticket lifecycle, master-data management, role-filtered listings, or relation-manager comments.

## Evidence Ledger

| Claim | Label | Evidence |
|---|---|---|
| Product is Laravel/Filament helpdesk | Observed | `README.md`, `composer.json`, `AdminPanelProvider.php` |
| Ticket CRUD and workflow exists | Observed | `TicketResource.php`, ticket pages, `TicketPolicy.php`, `Ticket.php` |
| One MCP tool implements exactly two account-to-ticket paths | Observed | `routes/ai.php`, `HelpdeskServer.php`, `HelpdeskIntakeTool.php`, `HelpdeskIntakeSession.php`, MCP feature tests |
| OTP login exists | Observed | `PhoneLogin.php`, `WhatsAppOtpService.php`, `PhoneOtpLoginTest.php` |
| Socialite registration disabled behavior is tested | Observed | `SocialiteController.php`, `SocialiteRegistrationDisabledTest.php` |
| Business rules are stakeholder-approved | Unknown | No SRS, decision log, or validation log existed before this reconstruction |
| Role spelling is consistent | Conflict | Seeders and README use `Staff Unit`; several notifications and checks use `Staf Unit` |
