# UC-007 Email OTP Or Magic Link Login

Status: Reviewed

## Evidence

Observed in `EmailLogin.php`, `EmailOtpService.php`, `EmailLoginMail.php`, `EmailOtpLoginTest.php`. Replaces the removed Socialite (Google) login linking.

## Flow

- Trigger: User opens `/email-login` from the login page's Email button and enters an email address.
- Preconditions: The default mailer really sends mail (not `log`/`array`) and `MAIL_FROM_ADDRESS` is set.
- Main path:
  1. System normalizes the email and applies send rate limits.
  2. System looks for exactly one active, non-deleted user with that email whose provenance is not `legacy_review_required`.
  3. When found, system expires older pending codes for that email, stores a bcrypt-hashed 6-digit code plus a SHA-256-hashed 64-character magic-link token in one `email_otps` record, and sends both in one email synchronously.
  4. The page moves to the code step whether or not an email was sent.
  5. User types the code, or opens the magic link. The link page exchanges the token through `wire:init` after render, not on GET.
  6. System locks the user and the record, checks expiry, attempt count, account state, and that the account email still matches, then consumes the record.
  7. System records `email_verified_via = email_link` when the email had no trusted provenance, logs the user in, regenerates the session, and redirects to the intended panel URL.
- Exception: Unknown, inactive, soft-deleted, shared, or legacy-review emails receive no email and the same code-step response.
- Exception: Wrong, expired, reused, superseded, or exhausted codes and links, or an account deactivated or re-emailed after sending, return a generic error and do not log in.
- Exception: Rate limits reject excess sends, verifications, and link exchanges.
- Exception: A delivery failure expires the new record and shows a generic error.
- Postconditions: The user is authenticated and the email has trusted provenance. No account is created by this flow.

## Data Used

ENT-001 User, ENT-013 EmailOtp.

## Acceptance Criteria

- AC-UC-007-01: A valid code or magic link logs in an active account exactly once; using one consumes the other.
- AC-UC-007-02: Wrong, expired, reused, superseded, exhausted, or unknown codes and links are rejected.
- AC-UC-007-03: Inactive, soft-deleted, unknown, shared, and legacy-review emails receive no code and cannot log in, without a distinguishable first-step response.
- AC-UC-007-04: Opening the magic-link URL alone does not consume it.
- AC-UC-007-05: Send, verify, and link exchange are rate limited.
