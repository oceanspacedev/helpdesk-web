# UCIC UC-006 Phone OTP Login

Status: Reviewed

## Contract

- Interfaces: `/phone-login` with server-side Livewire send/verify actions, plus WhatsApp gateway providers.
- Authentication: None at OTP request start; ends with Laravel session login.
- Request fields: phone on send; phone and six-digit OTP on verify; name only after a verified unknown number reaches manual registration.
- Validation: Phone is canonicalized before OTP, but Helpdesk/Talenta resolution occurs only after OTP succeeds. Manual completion additionally requires a short-lived server-side proof bound to the same session and phone.
- Cache state: The unresolved OTP challenge and later manual-registration proof are scoped to the server session and expire automatically.
- Success: OTP verification logs in an existing user, safely creates an exact Talenta user, or opens verified manual-name completion; successful manual completion creates the phone-only user and redirects to `/admin` without a second OTP.
- Error cases: Inactive/deleted/ambiguous identity, changed Talenta data, failed delivery, invalid/expired OTP, or stale pending state.
- Side effects: WhatsApp message and cache writes occur when sending; a new user is created only after successful OTP; the session is regenerated with a phone-verification marker and no remember-me token. Manual registration stores neither email nor password, and phone OTP never marks email as verified.
