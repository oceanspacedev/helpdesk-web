# UC-007 Socialite Login Linking

Status: Reviewed

## Evidence

Observed in `SocialiteController.php`, `SocialiteRegistrationDisabledTest.php`, `config/filament-socialite.php`.

## Flow

- Trigger: User opens `/auth/{provider}` and returns via callback.
- Preconditions: Provider returns user id, name, and email.
- Main path:
  1. System redirects to provider.
  2. Callback obtains provider user.
  3. System finds existing `socialite_users` row or existing helpdesk user by email.
  4. System creates provider link for existing helpdesk user.
  5. System logs user in and redirects to dashboard.
- Alternative: If registration is enabled and no user exists, system creates a user and assigns `User` role.
- Exception: When registration is disabled and no matching helpdesk user exists, system returns login error.
- Postconditions: Existing user can authenticate via provider.

## Data Used

ENT-001 User, ENT-010 SocialiteUser.

## Acceptance Criteria

- AC-UC-007-01: Unknown provider user is not auto-registered when registration disabled.
- AC-UC-007-02: Existing helpdesk user can link provider identity.

