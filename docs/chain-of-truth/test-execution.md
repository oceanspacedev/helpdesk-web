# Test Execution

Status: Reviewed

## MCP-Only Verification Set

The current focused verification command is:

```bash
php artisan test tests/Feature/HelpdeskMcpTest.php tests/Feature/HelpdeskMcpSettingsTest.php tests/Feature/HelpdeskMcpHttpFlowTest.php tests/Feature/HelpdeskIntakeChaosEvalTest.php tests/Feature/HelpdeskTicketCreationServiceTest.php tests/Feature/PhoneOnlyReporterEmailMigrationTest.php --do-not-cache-result
```

Result on 2026-08-29: **48 passed, 1,556 assertions**.

The full regression command is:

```bash
php artisan test --do-not-cache-result
```

Result on 2026-08-29: **161 passed, 2,267 assertions**.

The focused ticket mailbox and authorization command is:

```bash
php artisan test tests/Feature/TicketMailboxAccessTest.php tests/Feature/CommentShieldPolicyTest.php tests/Unit/PermissionSeederSafetyTest.php --do-not-cache-result
```

Result on 2026-08-29: **27 passed, 174 assertions**.

Additional checks:

```bash
composer validate --no-check-publish
vendor/bin/pint --test --dirty
git diff --check
php artisan route:list --path=mcp/helpdesk
php artisan route:list --path=phone-login
php artisan route:list --path=admin
```

Pint passed for all changed and newly added PHP files, and `git diff --check` passed. The route audit found exactly three MCP transport routes (`GET|HEAD`, `DELETE`, and `POST`) and no legacy Helpdesk REST routes. Composer metadata is valid; strict validation reports only the pre-existing unbound `@dev` constraint for `kungfufafa/mekaya-theme`.

## Coverage Included

- MCP discovery of only `helpdesk_intake`, token isolation, multi-turn state, A/B/C/D channel-user isolation, phone OTP, trusted WhatsApp assertion, the existing-account-to-ticket path, consent-gated prerequisite-account-to-ticket path, cancellation without writes when account creation is declined, Talenta resolution, stable external-user binding, no-binding local MCP behavior, replay, idempotency, and Auth-context restoration for long-lived processes.
- Super Admin-only MCP settings UI, environment fallback before first save, encrypted database storage, blank-secret preservation, and immediate database-token authentication.
- OTP-first web phone resolution, session-and-phone-bound manual-registration proof, inactive/deleted/duplicate collisions, owner-drift rejection, cache/delivery/tampering failures, trusted panel session markers, and email trust provenance.
- Socialite configuration bypass, new and existing trusted links, and rejection of unknown, unverified, legacy-review, inactive, or soft-deleted users.
- Canonical-phone and legacy-email migrations, employee matching, OTP rate limits/locks, MCP ticket creation, and intake chaos evaluation.
- Cross-unit Ticket Masuk/Keluar scopes, sender-versus-processor policy separation, Filament tabs and workflow actions, stale-responsible takeover, authoritative multi-unit membership, legacy `Staf Unit` migration, comment moderation, notification fallbacks, scoped ticket relation managers/counts, and read-only soft-deleted tickets.

## Not Executed

- Browser or Playwright end-to-end tests against a production-like Filament deployment, external WhatsApp gateway, Talenta service, Redis cluster, or reverse proxy. Filament mailbox tabs and workflow actions are covered at Livewire component level.
- MCP stdio end-to-end, HTTP throttle/teardown, PHP 8.2 matrix, and static analysis (PHPStan/Larastan is not installed in this project).
- Production deployment, migration, session purge, or live smoke test.
