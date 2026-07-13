# Codebase Baseline

Status: Reviewed  
Mode: Brownfield documentation and evaluation  
Evidence date: 2026-07-12  
Method note: This documentation uses the Chain of Truth method by Farid Suryanto and Muhammad Ibnu Athoillah. References: https://faridsurya-dev.github.io/Vibe-Coding-Research/welcome, https://faridsurya-dev.github.io/Vibe-Coding-Research/en/1-concept/what-is-chain-of-truth, https://github.com/faridsurya-dev/vibe_coding_simple_case, https://doi.org/10.5281/zenodo.20767965.

## Executive Outcome

The repository implements a Laravel 12 and Filament 4 web helpdesk with ticket management, master data administration, role-based visibility, WhatsApp OTP login, Socialite login linking, WhatsApp gateway sending, and ITA/WhatsApp API actions for ticket creation, lookup, and commenting. Confidence is medium-high for implementation facts because they are observed in source, routes, migrations, seeders, and tests. Confidence is medium for business intent because most requirements are reconstructed from implementation rather than stakeholder-approved artifacts.

## Scope

- Observed: `/` redirects to the Filament admin panel at `/admin`.
- Observed: Filament resources exist for Ticket, User, Unit, Problem Category, Ticket Status, and Business Entity.
- Observed: API routes under `/api/integrations/whatsapp/helpdesk/*` expose master data, classification validation, and action handling.
- Observed: web routes expose `/phone-login`, `/phone-login/verify`, and Socialite provider redirects/callbacks.
- Inferred: The product supports internal Complete Selular helpdesk operations and an ITA WhatsApp channel for reporters.

## Runtime And Dependencies

- Observed: PHP requirement is `^8.2`.
- Observed: `laravel/framework` is `^12.61`, `filament/filament` and `filament/infolists` are `^4.11`.
- Observed: Key packages include Filament Shield, Filament Breezy, Filament Socialite, Laravel Sanctum, Laravel Socialite, Guzzle, Pusher, Filament Excel, Apex Charts, Filament Exceptions, Overlook, and a Mekaya theme repository.
- Observed: Node frontend tooling uses Vite, Tailwind CSS 4, Axios, and Laravel Vite plugin.
- Unknown: Production database, queue, cache, mail, storage, and deployment topology are not documented in repository docs.

## Entry Points

- Observed: `routes/web.php` defines `/`, `/phone-login`, `/phone-login/verify`, `/auth/{provider}`, and `/auth/{provider}/callback`.
- Observed: `routes/api.php` defines authenticated `/api/user` and unauthenticated WhatsApp integration routes for master data, classification validation, and actions.
- Observed: `app/Providers/Filament/AdminPanelProvider.php` configures panel id `admin`, path `admin`, brand `Helpdesk`, Filament login, plugins, resources, pages, widgets, and theme.

## Module Map

| Module | Observed elements | Responsibility |
|---|---|---|
| Admin panel | `AdminPanelProvider`, Filament resources and pages | Web UI, resource CRUD, dashboard widgets |
| Tickets | `TicketResource`, `Ticket`, `TicketPolicy`, ticket pages, relation managers | Ticket lifecycle, comments, history, export |
| Master data | Unit, ProblemCategory, Priority, TicketStatus, BusinessEntity resources and models | Selection lists and operational classification |
| Users and access | User resource, role relations, policies, Filament Shield, Breezy, Socialite | User management, permissions, login/profile |
| Phone OTP | `PhoneLogin`, `PhoneOtpLoginController`, `WhatsAppGateway` | WhatsApp OTP delivery and phone-based login |
| ITA/WhatsApp API | integration controllers and services | API ticket create/get/comment, form options, classification |
| Notifications | Ticket and comment model events, notification classes | New ticket, comment, closed ticket notifications |
| Tests | PHPUnit feature and unit tests | Regression coverage for current critical integrations |

## Actors And Permissions

- Observed: Seeded roles are `Super Admin`, `Admin Unit`, and `Staff Unit`.
- Observed: README also lists dummy accounts for Super Admin, Admin Unit, Staff Unit, and General User.
- Observed: Several code paths check `Staf Unit` while seeders create `Staff Unit`, creating a role-name conflict.
- Observed: `User::canAccessPanel()` allows only active users to access Filament.
- Observed: `TicketPolicy` allows Admin Unit to view owned tickets or tickets in the user's units, Staff Unit to view owned or assigned tickets, and regular users to view owned tickets.
- Inferred: A regular user is represented by the absence of admin/staff role or a `User` role assigned elsewhere.

## Critical Journeys

- UC-001: Authenticated users create tickets through Filament.
- UC-002: Admin Unit or Super Admin processes, cancels, or completes open/in-progress tickets from the ticket view page.
- UC-003: Participants add comments to tickets in the ticket relation manager and notifications are sent.
- UC-004: Verified ITA/WhatsApp actors create tickets through the integration API, including auto-registering phone-only reporters for create action.
- UC-005: Verified ITA/WhatsApp actors retrieve a ticket or add a comment when authorized.
- UC-006: Users log in with a phone number OTP sent through WhatsApp.
- UC-007: Existing helpdesk users link Socialite identities when registration is disabled.
- UC-008: Admin users manage master data and users through Filament resources.

## Data Stores And Entities

- Observed: Relational tables include `users`, `tickets`, `comments`, `ticket_histories`, `units`, `problem_categories`, `priorities`, `ticket_statuses`, `business_entities`, `user_entities`, `socialite_users`, permission tables, notifications, activity log, failed jobs, and personal access tokens.
- Observed: Ticket soft deletes are enabled and ticket status dates include `approved_at` and `solved_at`.
- Observed: User email has been made nullable by a 2026 migration to support ITA reporters without email.
- Observed: Comments use column `tiket_id`, not `ticket_id`.
- Observed: `supporting_attachments` is a nullable JSON column on tickets.

## External Interfaces

- Observed: WhatsApp outbound gateway supports WAHA and Fonnte providers through Guzzle and config-driven credentials.
- Observed: ITA/WhatsApp integration actions require `actor.is_verified` to be true.
- Observed: Socialite routes call `Socialite::driver($provider)` and link/create `socialite_users`.
- Unknown: API authentication for `/api/integrations/whatsapp/*` is not documented or enforced by route middleware in the observed route file.

## Operational Topology

- Observed: README installation steps include Composer install, `.env`, key generation, migration, seeding, storage link, and `php artisan serve`.
- Observed: `package.json` supports `npm run dev` and `npm run build`.
- Unknown: CI/CD, queue workers, scheduled jobs, production runbook, log aggregation, and alerting are not documented.

## Test Coverage

- Observed: Feature tests cover root redirect, disabled admin registration, disabled Socialite registration, phone OTP login paths, email-nullable migration behavior, WhatsApp helpdesk actions, validation, and sanitized method errors.
- Observed: Unit tests cover WhatsApp gateway WAHA sending, Fonnte fallback, and Indonesian phone normalization.
- Gap: There is no observed browser/E2E coverage for Filament ticket lifecycle, master-data management, role-filtered listings, or relation-manager comments.

## Evidence Ledger

| Claim | Label | Evidence |
|---|---|---|
| Product is Laravel/Filament helpdesk | Observed | `README.md`, `composer.json`, `AdminPanelProvider.php` |
| Ticket CRUD and workflow exists | Observed | `TicketResource.php`, ticket pages, `TicketPolicy.php`, `Ticket.php` |
| ITA/WhatsApp create/get/comment exists | Observed | `routes/api.php`, `WhatsappHelpdeskActionService.php`, `WhatsappHelpdeskActionTest.php` |
| OTP login exists | Observed | `PhoneLogin.php`, `PhoneOtpLoginController.php`, `PhoneOtpLoginTest.php` |
| Socialite registration disabled behavior is tested | Observed | `SocialiteController.php`, `SocialiteRegistrationDisabledTest.php` |
| Business rules are stakeholder-approved | Unknown | No SRS, decision log, or validation log existed before this reconstruction |
| Role spelling is consistent | Conflict | Seeders and README use `Staff Unit`; several notifications and checks use `Staf Unit` |

