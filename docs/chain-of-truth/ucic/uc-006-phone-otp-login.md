# UCIC UC-006 Phone OTP Login

Status: Reviewed

## Contract

- Interfaces: `/phone-login`, `/phone-login/verify`, WhatsApp gateway providers.
- Authentication: None at OTP request start; ends with Laravel session login.
- Request fields: phone on send; phone and six-digit OTP on verify.
- Validation: Phone must normalize to at least 10 digits and match an active user.
- Cache key: `phone-otp-login:` plus SHA1 of stored user phone.
- Success: OTP send redirects to verify route; verify logs in user and redirects to `/admin`.
- Error cases: Unknown/inactive phone, failed WhatsApp send, invalid/expired OTP.
- Side effects: WhatsApp message sent, cache write/delete, optional `email_verified_at` update, session regeneration.

