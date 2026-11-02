# UCIC UC-007 Email OTP Or Magic Link Login

Status: Reviewed

## Contract

- Interfaces: Livewire page `/email-login` (`email-login`) and `/email-login/link/{token}` (`email-login.link`, token `[A-Za-z0-9]{64}`).
- External dependency: Laravel default mailer. The login page offers Email only when its transport is not `log`/`array` and a from-address is set.
- Inputs: Email address, then a 6-digit code or the magic-link token.
- Storage: `email_otps` holds `user_id`, normalized `email`, `purpose`, bcrypt `otp_hash`, unique SHA-256 `token_hash`, `expires_at`, `verified_at`, `attempt_count`. Lifetime is `services.email_login.expires_in` (`EMAIL_LOGIN_EXPIRES_IN`, default 600 s, minimum 60 s). Five wrong codes expire the record.
- Single use: Code and link share one record; consuming either sets `verified_at`. A new request or an account email change invalidates pending records.
- Eligible account: Exactly one active, non-deleted user with that email. No account is created.
- Rate limits: send 3/5 min and 10/h per email plus 20/min per IP; verify 15/5 min per email plus 20/min per IP; link exchange 20/min per IP. All limits are checked before any is counted.
- Success: Records `email_verified_via = email_link` when the email lacked trusted provenance, logs in through the Filament guard without remember-me, regenerates the session, and returns Filament's login response.
- Rejections: Generic messages that do not reveal whether an email is registered or active.
