# UCIC UC-008 Manage Master Data And Users

Status: Reviewed

## Contract

- Interfaces: Filament resources for users, units, problem categories, ticket statuses, and business entities.
- Authentication: Active Filament user with trusted verified email authentication or a matching current phone-OTP session.
- Authorization: Spatie permissions and policy methods generated or configured through Filament Shield.
- Data operations: List, create, edit, view, delete, restore, force-delete depending on each resource.
- Side effects: Master data changes affect ticket forms and WhatsApp form options.
- User identity controls: Administrators may record email-verification provenance only after review. Self-service profile changes are limited to display name; phone and email require dedicated verification flows.
- Error cases: Permission denial; untrusted authentication provenance; validation errors for required fields and max lengths.
