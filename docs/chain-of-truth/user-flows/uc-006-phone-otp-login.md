# UC-006 Phone OTP Login

Status: Reviewed

## Evidence

Observed in `PhoneLogin.php`, `PhoneOtpLoginController.php`, `InteractsWithPhoneNumbers.php`, `WhatsAppGateway.php`, and `PhoneOtpLoginTest.php`.

## Flow

- Trigger: User opens `/phone-login`.
- Preconditions: User has an active account with a resolvable phone number.
- Main path:
  1. User enters phone number.
  2. System normalizes Indonesian phone format.
  3. System finds active user by candidate phone formats.
  4. System generates six-digit OTP and sends it via WhatsApp gateway.
  5. System stores hashed OTP in cache with TTL.
  6. User submits phone and OTP at `/phone-login/verify`.
  7. System verifies hash, clears cache, marks email verified if needed, logs in user, and redirects to `/admin`.
- Exceptions: Unknown phone, failed send, invalid code, or expired code blocks login.
- Postconditions: User is authenticated and session is regenerated.

## Data Used

ENT-001 User, external WhatsApp gateway, cache.

## Acceptance Criteria

- AC-UC-006-01: Valid phone and OTP logs in user.
- AC-UC-006-02: Unknown phone is rejected without sending WhatsApp message.
- AC-UC-006-03: Wrong OTP is rejected.

