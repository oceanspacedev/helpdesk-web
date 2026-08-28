# UC-007 Socialite Login Linking

Status: Reviewed

## Evidence

Observed in `SocialiteController.php`, `SocialiteRegistrationDisabledTest.php`, `config/filament-socialite.php`.

## Flow

- Trigger: User opens `/auth/{provider}` and returns via callback.
- Preconditions: Provider returns an identity and the linked or email-matched Helpdesk user is active, non-deleted, and has trusted email-verification provenance.
- Main path:
  1. System redirects to provider.
  2. Callback obtains provider user.
  3. System finds an existing `socialite_users` row or one existing Helpdesk user by email.
  4. System validates the Helpdesk account state and trusted email provenance, then creates a provider link when one does not already exist.
  5. System logs user in and redirects to dashboard.
- Exception: Unknown users are never auto-registered, even when package configuration enables registration.
- Exception: Unverified or legacy-review email, inactive account, or soft-deleted account returns a login error and creates no provider link.
- Postconditions: Existing user can authenticate via provider.

## Data Used

ENT-001 User, ENT-010 SocialiteUser.

## Acceptance Criteria

- AC-UC-007-01: Unknown provider user is never auto-registered.
- AC-UC-007-02: An active existing Helpdesk user with trusted email provenance can link and reuse a provider identity.
- AC-UC-007-03: Existing links and new email matches are rejected for legacy-review, unverified, inactive, or soft-deleted accounts.
