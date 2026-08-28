# UC-006 Phone OTP Login

Status: Reviewed

## Evidence

Observed in `PhoneLogin.php`, `WhatsAppOtpService.php`, `InteractsWithPhoneNumbers.php`, `WhatsAppGateway.php`, and `PhoneOtpLoginTest.php`.

## Flow

- Trigger: User opens `/phone-login`.
- Preconditions: The phone can be controlled through WhatsApp OTP. Existing inactive, deleted, or ambiguous Helpdesk identities are rejected.
- Main path:
  1. User enters phone number.
  2. System normalizes Indonesian phone format.
  3. Without looking up Helpdesk or Talenta, the system generates a six-digit OTP and sends the same pre-verification response for known and unknown numbers.
  4. System stores the hashed unresolved challenge in cache with TTL; no identity or account status is disclosed yet.
  5. User submits the OTP in the same server session.
  6. Only after OTP succeeds, the system resolves one active Helpdesk account, exactly one trusted Talenta record, or an unknown number.
  7. Existing and Talenta-backed users are logged in safely. An unknown number receives a short-lived server-side proof bound to the session and phone, then supplies a name and creates a phone-only account without a second OTP.
- Exceptions: Inactive/deleted/ambiguous identity, directory changes, failed delivery, invalid/expired OTP, or cache loss blocks login without creating a user.
- Postconditions: User is authenticated only in the regenerated phone-verified session. A manual account remains phone-only; email login and mail notifications require independently trusted verification provenance.

## Data Used

ENT-001 User, external WhatsApp gateway, cache.

## Acceptance Criteria

- AC-UC-006-01: Valid phone and OTP logs in user.
- AC-UC-006-02: Helpdesk/Talenta lookup and new-user persistence do not occur before OTP verification succeeds.
- AC-UC-006-03: Wrong OTP, stale state, or identity ambiguity is rejected without creating a user.
