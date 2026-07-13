# UCIC UC-008 Manage Master Data And Users

Status: Reviewed

## Contract

- Interfaces: Filament resources for users, units, problem categories, ticket statuses, and business entities.
- Authentication: Filament authenticated active user.
- Authorization: Spatie permissions and policy methods generated or configured through Filament Shield.
- Data operations: List, create, edit, view, delete, restore, force-delete depending on each resource.
- Side effects: Master data changes affect ticket forms and WhatsApp form options.
- Error cases: Permission denial; validation errors for required fields and max lengths.

