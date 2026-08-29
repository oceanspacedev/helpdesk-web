# Helpdesk MCP

Helpdesk MCP exposes one deterministic, multi-turn tool with exactly two business paths:

1. Reuse an existing eligible reporter account, then create its ticket.
2. When no eligible account exists, create the required reporter account first, then continue the same intake and create its ticket.

Account creation exists only as a ticket prerequisite; it is not a standalone account-management feature. MCP does not expose ticket lookup, comments, updates, workflow actions, or administration. Codex, Atlas relaying WhatsApp, and other AI hosts or channel bridges are generic MCP clients or gateways. They all consume the same contract; the Helpdesk server contains no vendor-specific branch.

Supported deployment modes:

- Production Streamable HTTP endpoint: `https://<helpdesk-host>/mcp/helpdesk`
- Local stdio for development or a trusted co-located client: `php artisan mcp:start helpdesk`

The single advertised tool is `helpdesk_intake`. Some MCP hosts display a namespaced name such as `helpdesk__helpdesk_intake`; integrations should use the name returned by `tools/list`.

In production, MCP is served by the same Laravel HTTP application and PHP workers as the Helpdesk web application. Do not run `php artisan mcp:start helpdesk` as a production daemon. That command is only the local stdio transport.

## Production quickstart

This is the short path from a deployed Helpdesk release to the first MCP report. If this release upgrades an existing Helpdesk database, complete the maintenance-window procedure in [Install](#install) before reopening traffic; the short migration command below is only for a new, empty database.

### 1. Prepare the production runtime

Use the normal immutable Laravel release behind HTTPS. The web server must send `/mcp/helpdesk` to `public/index.php`, preserve the `Authorization` and `MCP-Session-Id` headers, accept JSON `POST` requests, and never cache this endpoint. All application nodes must use the same database, `APP_KEY`, WhatsApp gateway configuration, and Talenta employee file. MCP server-wide values live in the shared database; tokens and identity secrets are encrypted with `APP_KEY`.

For more than one application node, Redis is required for the shared MCP draft, OTP, registration, rate-limit, lock, cache, and session state. A minimal MCP-related production environment is:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://helpdesk.example.com

# Keep one existing value on every node; do not regenerate it during deploys.
APP_KEY=base64:REPLACE_WITH_THE_SHARED_LARAVEL_KEY

CACHE_DRIVER=redis
SESSION_DRIVER=redis

# Required because reporter ownership is proven through WhatsApp OTP.
WAG_URL=https://whatsapp-gateway.example.com
WAG_TOKEN=REPLACE_WITH_THE_WHATSAPP_GATEWAY_TOKEN
WHATSAPP_OTP_TTL_MINUTES=5

# Optional when the release-bundled file is used. Otherwise use one stable,
# readable file path on every node.
# TALENTA_EMPLOYEE_FILE=/srv/helpdesk/shared/talenta-list-employee.json
```

After migrations, sign in as Super Admin and open `/admin/pengaturan-mcp`. Generate a different MCP bearer token for each client or gateway principal so it can be revoked independently:

```bash
openssl rand -hex 32
```

Add every token with a clear client name on the settings page, save, and give each client only its own token. The database ciphertext is protected by `APP_KEY`; still treat the UI and database backup as sensitive. Never commit tokens. `HELPDESK_MCP_CLIENT_TOKEN` in the client examples below is a client-machine environment variable, not a server setting.

### 2. Deploy the release

For a new empty database, after the normal application configuration is present:

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize
php artisan queue:restart
```

Do not run the dummy database seeder in production. For an existing production database, do not use the simple migration command above; follow [Install](#install), including the write freeze, backup/restore test, canonical-phone duplicate audit, administrator review, and session purge.

### 3. Check the deployed endpoint

On an application node, confirm that Laravel registered the MCP endpoint:

```bash
php artisan route:list --path=mcp/helpdesk -v
```

The output must include `POST mcp/helpdesk` with the `mcp-pre-auth` IP throttle before `VerifyHelpdeskMcpToken`, followed by the authenticated `mcp` throttle. From outside the network, `GET https://helpdesk.example.com/up` must return HTTP 200. Opening `/mcp/helpdesk` directly in a browser returns HTTP 405 by design because an MCP client initializes it with JSON-RPC over `POST`.

### 4. Connect and try one client

Choose one of the client configurations below, reload the client, and confirm it discovers exactly one tool named `helpdesk_intake`. Then follow [Try the first report](#try-the-first-report). No client-specific code is required on the Helpdesk server.

## Install

This section is the safe cutover runbook for an existing Helpdesk production database. It is intentionally more conservative than the new-environment quickstart above.

For local development, run the application migrations, then configure one or more client tokens through **Admin → Pengaturan → Pengaturan MCP**:

```bash
php artisan migrate
```

Production must use a maintenance/write-freeze cutover, not a mixed-version rolling deploy. New code requires `users.phone_normalized`; old code does not dual-write it and used an unsafe pre-OTP registration sequence. Back up the database and test its restore, drain all old HTTP nodes and queue workers, stop new `/phone-login`, MCP, and trusted WhatsApp gateway requests, then run migrations from the immutable new release before any new node receives traffic.

Migration `000004` backfills the canonical phone. Migration `000005` deliberately stops if a canonical duplicate exists, then adds the database unique constraint after cleanup. While traffic is frozen, run `000004` first, then audit before continuing with `000005`:

```bash
php artisan migrate --path=database/migrations/2026_08_28_000004_add_phone_normalized_to_users_table.php --force
```

```sql
SELECT id, phone
FROM users
WHERE phone IS NOT NULL AND phone_normalized IS NULL;

SELECT phone_normalized, COUNT(*) AS total, GROUP_CONCAT(id ORDER BY id) AS user_ids
FROM users
WHERE phone_normalized IS NOT NULL
GROUP BY phone_normalized
HAVING COUNT(*) > 1;
```

Migration `000006` will mark every old non-null email timestamp as `legacy_review_required` and revoke every remember token because the former phone flow could mark an email verified or persist a credential without proving email ownership. The account inventory and administrator review happen only after that migration has created and populated `email_verified_via`.

After canonical duplicates have been repaired, continue the migration but keep traffic closed:

```bash
php artisan config:clear
php artisan migrate --force
php artisan migrate:status
```

Before starting any application node, inventory every quarantined active account:

```sql
SELECT id, name, email, phone, email_verified_at, email_verified_via, is_active
FROM users
WHERE is_active = 1
  AND email_verified_via = 'legacy_review_required'
ORDER BY id;

SELECT phone_normalized, COUNT(*) AS total
FROM users
WHERE phone_normalized IS NOT NULL
GROUP BY phone_normalized
HAVING COUNT(*) > 1;
```

Bootstrap at least one reviewed administrator before reopening traffic if no administrator has a usable WhatsApp phone. Verify that exact person's email ownership out of band, then mark only that account as trusted; never bulk-promote all legacy timestamps:

```sql
UPDATE users
SET email_verified_via = 'admin'
WHERE id = :reviewed_admin_id
  AND email_verified_at IS NOT NULL
  AND email_verified_via = 'legacy_review_required';
```

An old Socialite link remains blocked while its user is `legacy_review_required`, inactive, or soft-deleted. Review those accounts through the same administrator-controlled process.

Purge every pre-cutover server-side session while traffic and workers remain stopped; clearing remember tokens alone does not invalidate an existing session. Use the configured session driver and target only its Helpdesk-owned data:

- `file`: clear only files inside the configured `SESSION_FILES` directory.
- `database`: delete rows only from this application's configured sessions table.
- `redis`: delete only this application's exact session-prefix keys; never use `FLUSHDB` on a shared Redis database.

Post-migration gates are: every migration is applied; the canonical duplicate query returns zero rows; at least one reviewed administrator can log in through a trusted email credential or phone OTP; an unreviewed legacy account is rejected; `/phone-login` sends and verifies OTP; MCP `tools/list` advertises only `helpdesk_intake`; and an authorized controlled intake reaches the expected identity question without creating a premature user or ticket. Only after those gates pass, start the new nodes/workers, finish the release, and reopen traffic:

```bash
php artisan optimize
php artisan queue:restart
```

Do not roll application code back to an old release after these migrations while keeping the migrated database: old code does not maintain the new identity invariants. If cutover cannot be completed, keep traffic closed and restore the tested pre-cutover database and matching old release together. Migration rollback alone cannot reconstruct revoked tokens or prior trust provenance.

In a multi-instance deployment, set both cache and session stores to shared Redis so MCP drafts, OTP challenges, pending registrations, rate limits, and per-phone locks survive requests reaching different instances. All nodes must share the same database, `APP_KEY`, cache/session prefixes, Redis databases, and Talenta file. Keep Redis private and persistent enough for the configured TTLs. Set `APP_URL` to the public HTTPS origin for framework-generated application URLs. Do not clear application cache after reopening traffic because doing so destroys live OTP challenges and MCP drafts.

The following server variables are rollout fallbacks only. They are read while no database settings row exists (or while an encrypted secret has not yet been set). Import the required values through `/admin/pengaturan-mcp`, verify a controlled MCP request, then remove the fallbacks from every node:

```dotenv
# One client
HELPDESK_MCP_TOKEN=replace-with-a-long-random-secret

# Multiple client principals (comma separated)
HELPDESK_MCP_TOKENS=whatsapp-gateway-secret,chat-gateway-secret

HELPDESK_MCP_INTAKE_TTL_MINUTES=30
HELPDESK_MCP_RATE_LIMIT_PER_MINUTE=300

# Required to deliver OTP for generic MCP channels
WAG_URL=https://whatsapp-gateway.example.com
WAG_TOKEN=replace-with-gateway-token
WHATSAPP_OTP_TTL_MINUTES=5

# Optional dedicated pepper for stored identity hashes (APP_KEY is fallback)
HELPDESK_MCP_IDENTITY_PEPPER=a-stable-random-secret

# Optional fast path for a trusted WhatsApp webhook gateway
HELPDESK_MCP_IDENTITY_ASSERTION_SECRET=another-long-random-secret
HELPDESK_MCP_IDENTITY_ASSERTION_LEEWAY_SECONDS=300
```

The local stdio identity is the one exception: it must remain different for every local client, so it is not moved to the shared UI/database. Set it in that client's process environment:

```dotenv
HELPDESK_MCP_LOCAL_CLIENT_ID=my-local-mcp-client
```

Every active token on the settings page is accepted as a separate integration principal. Drafts, durable bindings, and idempotency records are scoped to that principal, so changing a token does not migrate them. During rotation, add the new token first and keep the old token active until its intakes finish; users arriving through the new token will link their WhatsApp identity again. Never put a token in a tool argument, conversation ID, log message, or prompt.

The UI accepts intake TTL values from 10 to 1,440 minutes, authenticated request limits from 60 to 600 per token per minute, and assertion clock tolerance from 30 to 3,600 seconds. A fixed cheap IP throttle also runs before token lookup so invalid-token floods do not bypass rate limiting or freely amplify database work.

## Connect clients

Every remote client uses the same values:

```text
Transport: Streamable HTTP
URL: https://helpdesk.example.com/mcp/helpdesk
Header: Authorization: Bearer <token issued for this client>
Expected tool: helpdesk_intake
```

The configuration field names differ by client. Use the exact example for the selected host rather than the old generic JSON shape.

### Connect Codex

Following the [official Codex MCP configuration](https://developers.openai.com/codex/mcp), set `HELPDESK_MCP_CLIENT_TOKEN` in the environment that starts Codex, then add this to the personal `~/.codex/config.toml` or a trusted project's `.codex/config.toml`:

```toml
[mcp_servers.helpdesk]
url = "https://helpdesk.example.com/mcp/helpdesk"
bearer_token_env_var = "HELPDESK_MCP_CLIENT_TOKEN"
enabled_tools = ["helpdesk_intake"]
```

Restart the Codex host after changing the configuration. Run `codex mcp list` from the CLI, or open `/mcp` in the Codex terminal UI, and confirm `helpdesk` is enabled with `helpdesk_intake`. The ChatGPT desktop app, Codex CLI, and Codex IDE extension on the same host share this configuration. ChatGPT on the web does not read the local file; it needs a separately installed hosted plugin.

### Connect Cursor

Following the [official Cursor MCP configuration](https://cursor.com/docs/mcp), set `HELPDESK_MCP_CLIENT_TOKEN` in the environment that starts Cursor. Add this to the personal `~/.cursor/mcp.json`, or merge it into `.cursor/mcp.json` for a project-specific setup:

```json
{
  "mcpServers": {
    "helpdesk": {
      "url": "https://helpdesk.example.com/mcp/helpdesk",
      "headers": {
        "Authorization": "Bearer ${env:HELPDESK_MCP_CLIENT_TOKEN}"
      }
    }
  }
}
```

Restart Cursor, open its MCP settings, and confirm the `helpdesk` server and `helpdesk_intake` tool are available. Cursor supports environment interpolation in HTTP headers; its `envFile` option applies only to local stdio servers.

### Connect Google Antigravity

Following the [official Google Antigravity MCP configuration](https://antigravity.google/docs/mcp), use Antigravity's personal global file `~/.gemini/config/mcp_config.json` so the bearer token is not placed in the repository. Merge this entry into an existing `mcpServers` object; do not overwrite other configured servers:

```json
{
  "mcpServers": {
    "helpdesk": {
      "serverUrl": "https://helpdesk.example.com/mcp/helpdesk",
      "headers": {
        "Authorization": "Bearer REPLACE_WITH_THIS_CLIENT_TOKEN"
      }
    }
  }
}
```

Antigravity's current remote schema requires `serverUrl`; `url` and `httpUrl` are not supported. Its public documentation does not define environment interpolation for custom headers, so keep the literal token only in this private global file, never commit it, and restrict the file permissions (for example, `chmod 600 ~/.gemini/config/mcp_config.json` on Unix-like systems).

In Antigravity IDE, open **MCP Servers → Manage MCP Servers → View raw config**, save the file, then refresh the installed servers. In Antigravity CLI, run `/mcp` to reload the configuration, inspect connection status, and confirm `helpdesk_intake` is listed.

### Connect Atlas or a WhatsApp gateway

Atlas remains a generic MCP client or channel gateway; the Helpdesk server has no Atlas-specific endpoint. If Atlas can connect directly to a remote MCP server with custom headers, give it the common Streamable HTTP URL and its own bearer token. If Atlas relays WhatsApp events, keep the bearer token in the trusted gateway—not in the prompt—and implement the [gateway contract](#gateway-contract-whatsapp-telegram-discord-web-and-others).

For an inbound WhatsApp message such as `/helpdesk printer kasir mati`, the gateway passes the message unchanged to `helpdesk_intake` with `channel=whatsapp`, a stable sender `external_user_id`, a stable conversation ID, and a unique message ID. It then returns the tool's `user_reply` to WhatsApp and reuses the returned `intake_id` plus the same sender identity for every reply until terminal status. A trusted webhook gateway may add the documented signed `identity_assertion`; otherwise the safe flow sends WhatsApp OTP.

### Connect another MCP client

Any client can connect when it supports Streamable HTTP and custom request headers. Configure the common URL and bearer header shown above, then use its server/tool discovery screen to confirm that only `helpdesk_intake` is advertised. Do not put the token in a URL query string, prompt, tool argument, conversation ID, or log.

A client that cannot attach an HTTP `Authorization` header cannot connect securely to the production endpoint. Local stdio is only a development or trusted co-located option because it requires the Helpdesk source code, PHP dependencies, database access, and application environment on the client machine:

```json
{
  "mcpServers": {
    "helpdesk": {
      "command": "php",
      "args": [
        "/absolute/path/to/web-helpdesk/artisan",
        "mcp:start",
        "helpdesk"
      ],
      "env": {
        "HELPDESK_MCP_LOCAL_CLIENT_ID": "one-stable-id-for-this-client"
      }
    }
  }
}
```

For stdio, use a different stable `HELPDESK_MCP_LOCAL_CLIENT_ID` for every AI client so drafts, replay records, and any explicitly identified users remain scoped to the same client principal across process restarts. Reusable reporter identity also requires the tool call to supply a stable per-user `external_user_id`. If the local client ID is empty, the server uses a safe per-process principal; separate local clients cannot accidentally share state.

The installed Laravel MCP package currently advertises protocol versions through `2025-11-25`. Check client compatibility before deployment; do not add an unsupported version string without upgrading the SDK.

## Try the first report

After the client discovers `helpdesk_intake`, send this as a normal prompt:

```text
Buat laporan helpdesk: printer kasir tidak bisa mencetak sejak pagi.
```

The AI client should call `helpdesk_intake` and relay each Helpdesk question unchanged. The expected user-visible sequence is:

1. Prove the reporter's WhatsApp number with OTP when no reusable verified binding exists.
2. Continue with the existing eligible Helpdesk account, or—only when no eligible account exists—ask for account-creation consent and the full name, create that prerequisite account, and resume the same intake.
3. Answer the ticket classification and detail questions.
4. Confirm the summary and receive the created ticket number.

`/helpdesk printer kasir tidak bisa mencetak` is also a valid intake message when the host forwards it as ordinary text. MCP connection alone does not register a slash command in Codex, Cursor, Antigravity, or every other host. If the client treats `/helpdesk` as an unknown or built-in command, use the natural-language prompt above or create a client-side shortcut that instructs the agent to use `helpdesk_intake`; no additional Helpdesk endpoint or MCP tool is needed.

A direct MCP client that does not supply a stable `external_user_id` will ask for WhatsApp OTP again on every new report by design. A WhatsApp/Atlas or other multi-user gateway should follow the [gateway contract](#gateway-contract-whatsapp-telegram-discord-web-and-others) so verified identity can be safely reused.

## Production troubleshooting

| Symptom | Check |
|---|---|
| Browser shows `405` at `/mcp/helpdesk` | Expected for a browser `GET`; use an MCP Streamable HTTP client, which sends JSON-RPC over `POST`. |
| Client receives `401` | Its bearer token must match an active entry in **Admin → Pengaturan MCP**; confirm the reverse proxy preserves `Authorization`. Before the first UI save, check the legacy environment fallback. |
| Client receives `404` | Confirm the deployed release contains `routes/ai.php`, the proxy routes the path to Laravel, and `php artisan route:list --path=mcp/helpdesk -v` lists it. |
| Client receives `429` | Check **Batas request** on the MCP settings page, the token principal, and the source IP limit. |
| Server connects but no tool appears | Reload/restart the client and inspect its MCP logs; the server must advertise exactly `helpdesk_intake`. |
| `/helpdesk` is unknown | The AI host intercepted it as a slash command; send `Buat laporan helpdesk: ...` as normal text. |
| OTP never arrives | Check `WAG_URL`, `WAG_TOKEN`, outbound network access, and WhatsApp gateway logs. |
| Intake disappears or restarts between requests | On multiple nodes, verify shared Redis cache/session configuration, consistent prefixes/databases, and the same `APP_KEY`. |
| A new direct-client report asks for OTP again | Expected without a stable `external_user_id`; use a conforming gateway for durable per-user bindings. |

## Two business paths in one intake tool

1. When a user asks to report an issue, call `helpdesk_intake` with the exact message. Omit `intake_id` on this first call. Set `start=true` if intent is already known but the message does not start with a phrase such as `lapor` or `create ticket`.
2. Forward `content[0].text` to the user. If the host only preserves structured output, use `structuredContent.user_reply`.
3. While `structuredContent.status` is `in_progress`, call the tool once for each new user reply and return the exact `intake_id` from the previous result. Every external-channel call must also resend the exact same gateway-injected `channel` and `external_user_id`, starting with the first call. This includes the `registration_consent` and `registration_name` steps.
4. Stop when `terminal=true` (`ticket_created` or `cancelled`) or the tool returns an error. A user who declines required account creation receives `cancelled`; no account or ticket is created. Repair invalid input/session context from the gateway; do not loop without a new user event. Never invent a reporter name, ticket number, or classification.

Before the ticket questions, the server resolves the reporter by verified WhatsApp number. If the channel user has no existing binding, it asks for a number, sends a six-digit WhatsApp OTP, and only then checks the Helpdesk user directory and Talenta. For a number absent from both, the same intake advances to `registration_consent`. Only an explicit yes advances to `registration_name`, where the user must type the full name. The server ignores the earlier `reporter_name` hint, atomically rechecks Helpdesk and Talenta, creates an active phone-only account with null email and password, and resumes the original ticket questions with the original issue data. A no answer cancels the intake without creating an account or ticket. Forward every question and response unchanged.

```text
verified phone
  |-- eligible Helpdesk account exists ----------------------> continue intake -> ticket_created
  `-- eligible Helpdesk account is missing
        |-- exactly one Talenta match -> provision required account at ticket commit
        `-- absent from both directories -> registration_consent
              |-- no -----------------------------------------> cancelled (no account, no ticket)
              `-- yes -> registration_name -> create required account
        `-----------------------------------------------------> continue same intake -> ticket_created
```

A trusted WhatsApp assertion replaces `phone_otp`, but it does not replace `registration_consent` or `registration_name` for an unknown number.

Web, WhatsApp, Telegram, Discord, Slack, and other gateways must provide `external_user_id` on the first and every later call. A direct `channel=mcp` client may omit it, but then the server deliberately does not read or write a durable reporter binding, so every new intake asks for WhatsApp proof again. A direct client that wants reusable identity must provide its own stable per-user `external_user_id`. For a multi-user gateway, that ID must identify the sender and must never be replaced with a shared group or thread ID.

First call:

```json
{
  "message": "VPN kantor putus sejak pagi",
  "channel": "slack",
  "reporter_name": "Ayu",
  "phone": "081234567890",
  "start": true,
  "external_conversation_id": "thread-991",
  "external_user_id": "user-42",
  "external_message_id": "message-1001"
}
```

Following call:

```json
{
  "intake_id": "hdi_handle_returned_by_the_server",
  "message": "123456",
  "channel": "slack",
  "external_user_id": "user-42",
  "external_message_id": "message-1002"
}
```

Structured result:

```json
{
  "ok": true,
  "status": "in_progress",
  "intake_id": "hdi_handle_returned_by_the_server",
  "step": "unit",
  "requires_input": true,
  "terminal": false,
  "replayed": false,
  "expires_at": "2026-08-28T12:30:00+00:00",
  "user_reply": "...",
  "ticket": null,
  "data": {
    "step": "unit"
  }
}
```

`content[0].text` is always the same as `structuredContent.user_reply`. A normal form question is not an MCP execution error. Invalid/expired handles, unsafe attachment URLs, and message-ID conflicts are returned with `isError=true`.

## Gateway contract (WhatsApp, Telegram, Discord, web, and others)

A gateway serving multiple users should inject these values; the LLM must not guess them:

| Field | Purpose |
|---|---|
| `external_conversation_id` | Stable source thread/conversation ID |
| `external_user_id` | Stable source sender ID; repeat exactly on every call to isolate A/B/C/D and remember a verified binding |
| `external_message_id` | Stable source message/webhook ID, reused on retries |
| `channel` | Lowercase source label; open-ended, not an enum; repeat exactly on every call |
| `reporter_name` | Optional untrusted display-name hint; never proof, consent, or the name used for inline registration |
| `phone` | Optional WhatsApp-number hint; the server asks when absent and verifies ownership |
| `identity_assertion` | Optional signed value injected only by a trusted WhatsApp webhook gateway |
| `attachment_urls` | Up to five HTTPS URLs, maximum 2048 characters each |

The server keys draft state by the authenticated client and its own random `intake_id`, then validates the original `channel` and `external_user_id` before every continuation or replay. A raw phone number identifies neither an intake nor an external user. Merely sending `channel=whatsapp`, `reporter_name`, or `phone` through the MCP tool cannot claim an existing Helpdesk account.

`external_user_id` and `intake_id` solve different problems:

- `intake_id` identifies one temporary form session.
- `(authenticated client, channel, external_user_id)` identifies a channel account such as Telegram user A, B, C, or D.
- The ticket owner is still a Helpdesk `User` selected by a WhatsApp number proven through OTP or a trusted gateway assertion. A stable external channel account is bound to that user after inline registration or successful ticket creation, so its next intake can reuse the identity without another OTP. A direct MCP intake that omits `external_user_id` remains deliberately unbound; it does not create a synthetic identity and must prove the phone again on the next intake.

For a trusted WhatsApp webhook gateway, `identity_assertion` uses `v1.<unix timestamp>.<hex hmac>`. Calculate HMAC-SHA256 with the assertion secret stored on the MCP settings page (or its legacy `HELPDESK_MCP_IDENTITY_ASSERTION_SECRET` fallback) over these newline-separated values:

```text
v1
<unix timestamp>
<sha256 of the MCP bearer token>
whatsapp
<external_user_id from the authenticated webhook>
<canonical 62... phone>
<external_message_id from the authenticated webhook event>
```

The gateway must generate and inject the phone, external user ID, unique external message ID, and assertion outside the LLM. The HMAC hex may be uppercase or lowercase. An assertion is claimable by only one `intake_id` during its validity window; the claim is stored in the database and survives cache loss, so replaying it into a new intake falls back to OTP. Without a valid assertion—even when `channel=whatsapp`—the safe fallback is OTP.

For a file-only user turn, pass `message` as an empty string plus `attachment_urls`. Gateways holding private Telegram/Discord/WhatsApp file handles must upload or proxy them to an HTTPS URL first. The Helpdesk server does not fetch URLs during intake; it stores validated links, so use durable URLs rather than short-lived signed links.

## Identity and retry behavior

- No new MCP ticket is owned by a phone-null pseudo-user. `external_user_id` never becomes a `users.identity` value.
- Talenta and Helpdesk-name lookup happen only after phone ownership is proven, so typing someone else's number does not reveal their name.
- If one normalized phone matches multiple Helpdesk accounts or Talenta employees, intake fails closed and asks for corrected directory data or another number instead of guessing an owner.
- The resolved Helpdesk user ID or Talenta fingerprint is checked again at ticket commit. If ownership changes while the form is being filled, no ticket is created and phone verification restarts. A newly verified different phone also clears prior consent and identity state.
- A known verified phone reuses its active Helpdesk user. A Talenta-only employee may be provisioned with the canonical WhatsApp number when the ticket is saved.
- A verified number absent from both Helpdesk and Talenta enters `registration_consent`; no user is created before an explicit yes and a valid full name typed at `registration_name`. `reporter_name` is discarded for this purpose. At the write boundary the server locks the canonical phone and rechecks active, deleted, duplicate, and Talenta matches. Only a still-unknown phone creates one active phone-only account with null email and password, then the same intake continues. A collision or changed directory fails closed instead of overwriting or duplicating an account.
- Declining the required inline account creation returns terminal `cancelled` and creates neither an account nor a ticket. The MCP server does not redirect the reporter to a web form or expose a third registration path.
- Durable bindings store HMAC hashes of external IDs and phones, not their plaintext values. A changed phone or inactive user invalidates the binding.
- Durable bindings are only read or written for a stable, caller-supplied `external_user_id`. A direct MCP call that omits it uses isolated intake state but must prove the phone again for every new intake.
- During an active intake, reusing the same `external_message_id` with the same message, attachments, start flag, routing fields, and identity fields replays the previous step without advancing twice.
- During that active intake, reusing it with any of those fields changed returns `SOURCE_MESSAGE_ID_REUSED`; another channel user cannot read or poison that replay cache.
- Final ticket creation is protected by a durable database idempotency record. Once terminal, any retry of that `intake_id` and its original channel-user context returns the existing ticket—even after cache loss—instead of processing new message content or creating a duplicate.
- Ticket model observers run under a temporary reporter Auth context that is always restored, including after exceptions, so a long-lived stdio/worker process cannot carry user A into user B's operation.
- In a multi-instance deployment, use a shared cache (Redis is recommended) for draft state and locks. Database idempotency still protects final creation.

## MCP version notes

Server `1.7.0` adds consent-gated inline account creation for unknown MCP reporters. After OTP or a trusted WhatsApp assertion, clients must forward `registration_consent`, then `registration_name` only after an explicit yes. Successful phone-only account creation continues the same intake to ticket creation; declining returns terminal `cancelled` with no account or ticket write.

Older phone-null `mcp-external:*` users and their historical tickets are left unchanged, but they are not trusted or backfilled automatically. Their next new MCP report must verify a WhatsApp number. Clients must forward `phone_otp` and inline account-prerequisite steps like every other server response. Direct MCP calls without a stable `external_user_id` no longer share reusable reporter identity.

See [SKILL.md](./SKILL.md) for an optional model instruction. It is not required for a standards-compliant MCP client because the tool description and schemas are self-contained.

## Verify

```bash
php artisan test tests/Feature/HelpdeskMcpTest.php
php artisan test tests/Feature/HelpdeskMcpHttpFlowTest.php
php artisan test tests/Feature/HelpdeskTicketCreationServiceTest.php
```

The HTTP flow tests cover tool discovery, text and structured output parity, OTP-gated WhatsApp/Talenta identity, trusted WhatsApp assertions, consent-gated inline phone-only account creation, cancellation without writes when account creation is declined, ignored `reporter_name` hints, isolated A/B/C/D sessions, stable channel-user bindings, no-binding direct MCP calls, open-ended Slack, server-minted state handles, source-message replay/conflict, ticket creation, and durable recovery after cache flush.
