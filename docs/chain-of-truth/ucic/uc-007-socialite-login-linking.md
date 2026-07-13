# UCIC UC-007 Socialite Login Linking

Status: Reviewed

## Contract

- Interfaces: `/auth/{provider}` and `/auth/{provider}/callback`.
- External dependency: Laravel Socialite provider.
- Inputs: Provider id, name, email.
- Existing link path: `socialite_users.provider` plus `provider_id` returns linked user.
- New link path: Existing helpdesk user found by email receives a new socialite user row.
- Disabled registration behavior: When no matching user exists and registration is disabled, return login error and create no user.
- Success: Laravel login and redirect to Filament dashboard.

