# Information Architecture As-Is

Status: Reviewed  
Source type: Reconstructed from routes and Filament resources.

## Route And Page Inventory

| ID | Page or endpoint | Type | Evidence | Related use cases |
|---|---|---|---|---|
| PAGE-001 | `/` | Web redirect | `routes/web.php` | UC-001 |
| PAGE-002 | `/admin` | Filament admin panel | `AdminPanelProvider.php` | UC-001, UC-002, UC-003, UC-008 |
| PAGE-003 | `/admin/tickets` | Filament resource list with Ticket Masuk and Ticket Keluar tabs for unit processors | `TicketResource.php`, `ListTickets.php` | UC-001, UC-002, UC-003 |
| PAGE-004 | `/admin/tickets/create` | Filament create page | `CreateTicket.php` | UC-001 |
| PAGE-005 | `/admin/tickets/{record}` | Filament view page | `ViewTicket.php` | UC-002, UC-003 |
| PAGE-006 | `/admin/tickets/{record}/edit` | Filament edit page | `EditTicket.php` | UC-002 |
| PAGE-007 | `/admin/users` | Filament resource | `UserResource.php` | UC-008 |
| PAGE-008 | `/admin/units` | Filament resource | `UnitResource.php` | UC-008 |
| PAGE-009 | `/admin/problem-categories` | Filament resource | `ProblemCategoryResource.php` | UC-008 |
| PAGE-010 | `/admin/ticket-statuses` | Filament resource | `TicketStatusResource.php` | UC-008 |
| PAGE-011 | `/admin/business-entities` | Filament resource | `BusinessEntityResource.php` | UC-008 |
| PAGE-012 | `/phone-login` | Phone login page | `PhoneLogin.php` | UC-006 |
| PAGE-013 | `/admin/my-profile` | Restricted self-service profile; name-only personal data and conditional trusted-password update | `MyProfile.php`, `PersonalInfo.php`, `UpdatePassword.php` | UC-008 |
| PAGE-014 | `/admin/pengaturan-mcp` | Super Admin settings for MCP client tokens, intake limits, and encrypted identity secrets | `HelpdeskMcpSettings.php`, `HelpdeskMcpSetting.php` | UC-004 |
| MCP-001 | `/mcp/helpdesk` | Generic HTTP transport for the single `helpdesk_intake` tool | `routes/ai.php`, `HelpdeskServer.php` | UC-004 |
| MCP-002 | local server `helpdesk` | Generic local transport for the same `helpdesk_intake` contract | `routes/ai.php`, `HelpdeskServer.php` | UC-004 |
| AUTH-001 | `/auth/{provider}` and callback | Web auth | `routes/web.php` | UC-007 |

## Navigation Structure

- Observed: Filament discovers all resources in `app/Filament/Resources`.
- Observed: Ticket navigation icon is `heroicon-o-ticket` with sort order 3.
- Observed: Admin Unit and Staff Unit open on `Tiket Masuk` (destination unit); `Tiket Keluar` contains tickets personally created by the current user. Global administrators use `Semua Tiket`, and general users see only `Tiket Keluar`.
- Observed: User, Unit, and Business Entity resources belong to navigation group `Master Data`.
- Observed: Problem Category and Ticket Status resources are not explicitly grouped in the observed code, although they behave as master/reference data.
- Observed: Dashboard and widgets are configured through `AdminPanelProvider`.
- Observed: the `Pengaturan` group contains the Super Admin-only MCP settings page; server-wide values are stored in one encrypted singleton database row.
- Observed: Mekaya's implicit `/admin/profile` and password-reset routes are disabled. Breezy's `/admin/my-profile` remains, but its personal-information form can update only the display name. Its password component is hidden and server-rejected unless the account has both trusted email provenance and an existing password.

## Feature Relationships

- Tickets depend on priorities, units, problem categories, ticket statuses, users, and business entities.
- A ticket is not copied between mailboxes: its owner is the sender, its unit is the destination, and its responsible user is the destination agent who claims processing.
- Problem categories depend on units.
- Users can be related to units through `user_entities` morph relations and also have legacy `unit_id`.
- Comments and ticket histories are child information under tickets.
- MCP intake reuses the same ticket and master-data entities used by the web ticket form, but reporters do not need to open or sign in to that form. Its only business paths are existing account to ticket, or prerequisite account creation followed by ticket creation in the same intake; it exposes no ticket-management or standalone account-management interface.

## IA Gaps

- PAGE-009 and PAGE-010 are functionally master data but are not grouped the same way as other master data resources.
- No existing IA document or sitemap was present before this reconstruction.
- Routes for Filament resource paths are convention-based; exact generated route names were not enumerated by running the app.
