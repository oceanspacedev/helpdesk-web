# Design System As-Is

Status: Reviewed  
Source type: Reconstructed from Filament panel configuration and resource schemas.

## Foundation

- Observed: The admin UI uses Filament 4 components.
- Observed: The panel brand name is `Helpdesk`.
- Observed: Primary color is Filament Orange through panel colors and Mekaya plugin colors.
- Observed: Mekaya theme, Filament Apex Charts, Filament Exceptions, Filament Shield, Filament Breezy, Overlook, and Filament Language Switch are installed or configured.
- Observed: Custom admin theme entry is `resources/css/admin/theme.css`.

## Components

| ID | Component | Evidence | Notes |
|---|---|---|---|
| CMP-001 | Filament Resource Table | Resource classes | Used for tickets, users, units, categories, statuses, entities. |
| CMP-002 | Filament Resource Form | Resource classes | Used for CRUD fields, validation, select options, upload constraints. |
| CMP-003 | Badge status column | `TicketResource.php` | Ticket status colors and icons for Open, In Progress, Cancel, Closed. |
| CMP-004 | Rich editor | `TicketResource.php`, `CommentsRelationManager.php` | Used for ticket description and comments. |
| CMP-005 | File upload | `TicketResource.php`, `CommentsRelationManager.php` | Tickets use constrained upload; comments use a separate 20 MB max size. |
| CMP-006 | Resource actions | Resource and page classes | View, edit, create, delete, restore, export, force-delete, workflow actions. |
| CMP-007 | Phone login simple page | `PhoneLogin.php` | Custom full-width Filament SimplePage for OTP send. |

## Interaction Patterns

- Observed: Ticket unit selection live-updates problem category validity.
- Observed: Ticket list supports search, date range, status, business entity, priority, unit, and trashed filters.
- Observed: Ticket view exposes status transition actions based on role, current status, and responsible user.
- Observed: Ticket list export uses Filament Excel with selected related columns.
- Observed: OTP login sends WhatsApp OTP then redirects to verification.

## Accessibility And Responsive Rules

- Observed: Filament and panel plugins provide baseline UI behavior.
- Unknown: No accessibility audit, browser screenshots, keyboard audit, or responsive verification artifacts exist in the repository.

## Design Gaps

- No human-approved design system document or prototype was present before reconstruction.
- No documented component states, empty states, error states, loading states, or responsive breakpoints were found beyond framework defaults.

