# UCIC UC-007 Socialite Login Linking

Status: Reviewed

## Contract

- Interfaces: `/auth/{provider}` and `/auth/{provider}/callback`.
- External dependency: Laravel Socialite provider.
- Inputs: Provider id, name, email.
- Existing link path: `socialite_users.provider` plus `provider_id` returns the linked user only when the Helpdesk account is active, not soft-deleted, and has trusted email-verification provenance.
- New link path: One active, non-deleted Helpdesk user found by email receives a new Socialite row only when that email has trusted verification provenance.
- Rejected identities: Unknown users, unverified email, `legacy_review_required`, inactive users, and soft-deleted users return a login error and create no user or provider link.
- Registration behavior: Socialite never auto-registers an unknown Helpdesk user, regardless of the package registration configuration.
- Success: Laravel login and redirect to Filament dashboard.
