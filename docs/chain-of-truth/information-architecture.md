# Information Architecture As-Is

Status: Reviewed  
Source type: Reconstructed from routes and Filament resources.

## Route And Page Inventory

| ID | Page or endpoint | Type | Evidence | Related use cases |
|---|---|---|---|---|
| PAGE-001 | `/` | Web redirect | `routes/web.php` | UC-001 |
| PAGE-002 | `/admin` | Filament admin panel | `AdminPanelProvider.php` | UC-001, UC-002, UC-003, UC-008 |
| PAGE-003 | `/admin/tickets` | Filament resource list | `TicketResource.php` | UC-001, UC-002, UC-003 |
| PAGE-004 | `/admin/tickets/create` | Filament create page | `CreateTicket.php` | UC-001 |
| PAGE-005 | `/admin/tickets/{record}` | Filament view page | `ViewTicket.php` | UC-002, UC-003 |
| PAGE-006 | `/admin/tickets/{record}/edit` | Filament edit page | `EditTicket.php` | UC-002 |
| PAGE-007 | `/admin/users` | Filament resource | `UserResource.php` | UC-008 |
| PAGE-008 | `/admin/units` | Filament resource | `UnitResource.php` | UC-008 |
| PAGE-009 | `/admin/problem-categories` | Filament resource | `ProblemCategoryResource.php` | UC-008 |
| PAGE-010 | `/admin/ticket-statuses` | Filament resource | `TicketStatusResource.php` | UC-008 |
| PAGE-011 | `/admin/business-entities` | Filament resource | `BusinessEntityResource.php` | UC-008 |
| PAGE-012 | `/phone-login` | Phone login page | `PhoneLogin.php` | UC-006 |
| PAGE-013 | `/phone-login/verify` | OTP verification page | `PhoneOtpLoginController.php` | UC-006 |
| API-001 | `GET /api/integrations/whatsapp/helpdesk/master-data` | JSON API | `routes/api.php` | UC-004 |
| API-002 | `POST /api/integrations/whatsapp/helpdesk/validate-classification` | JSON API | `routes/api.php` | UC-004 |
| API-003 | `POST /api/integrations/whatsapp/helpdesk/actions` | JSON API | `routes/api.php` | UC-004, UC-005 |
| API-004 | `/auth/{provider}` and callback | Web auth | `routes/web.php` | UC-007 |

## Navigation Structure

- Observed: Filament discovers all resources in `app/Filament/Resources`.
- Observed: Ticket navigation icon is `heroicon-o-ticket` with sort order 3.
- Observed: User, Unit, and Business Entity resources belong to navigation group `Master Data`.
- Observed: Problem Category and Ticket Status resources are not explicitly grouped in the observed code, although they behave as master/reference data.
- Observed: Dashboard and widgets are configured through `AdminPanelProvider`.

## Feature Relationships

- Tickets depend on priorities, units, problem categories, ticket statuses, users, and business entities.
- Problem categories depend on units.
- Users can be related to units through `user_entities` morph relations and also have legacy `unit_id`.
- Comments and ticket histories are child information under tickets.
- ITA/WhatsApp APIs reuse the same entities used by the web ticket form.

## IA Gaps

- PAGE-009 and PAGE-010 are functionally master data but are not grouped the same way as other master data resources.
- No existing IA document or sitemap was present before this reconstruction.
- Routes for Filament resource paths are convention-based; exact generated route names were not enumerated by running the app.

