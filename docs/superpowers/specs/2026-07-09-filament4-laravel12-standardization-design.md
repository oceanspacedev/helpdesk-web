# Design: Filament 4 + Laravel 12 Standardization

**Date:** 2026-07-09  
**Status:** Approved  
**Repo:** `web-helpdesk`

## Goal

Bring this helpdesk codebase to a clean **Laravel 12 + Filament 4 stable** standard while:

1. Keeping the app **runnable after every phase**
2. **Preserving Mekaya theme and existing custom Filament view overrides**
3. Avoiding business-feature work and UI redesign

## Current state (baseline)

| Area | Current | Gap |
|------|---------|-----|
| Laravel | 12.63.0 | Skeleton still Laravel 10-style (`Http/Kernel`, no `bootstrap/providers.php`) |
| Filament | `v4.12.0-beta2` via `^4.12@beta` | Should use Filament 4 **stable** (e.g. `^4.11` / latest 4.x stable that resolves plugins) |
| Stability | `minimum-stability: beta` | Prefer `stable` if dependency graph allows |
| Filament resources | Partial Schema migration | Legacy APIs remain: `TagsColumn`, `MultiSelect`, `reactive()` |
| Theme | Mekaya + `resources/views/vendor/filament-*` | Keep; patch only if upgrade breaks |
| Docs | README still says Laravel 10 / Filament v2 | Update to match stack |

Known legacy Filament API hit sites (non-exhaustive, verify during implementation):

- `app/Filament/Resources/UserResource.php` — `MultiSelect`, `TagsColumn`
- `app/Filament/Resources/UnitResource/RelationManagers/UsersRelationManager.php` — `TagsColumn`
- `app/Filament/Resources/TicketResource.php` — `reactive()`

## Constraints (from product owner)

- **Scope:** Full standardization (Laravel skeleton + Filament stack/API + docs)
- **Theme:** Keep Mekaya + custom views
- **Delivery:** Must remain runnable each step (no big-bang break window)
- **Out of scope:** New business features, redesign, Filament 5

## Approach

**Layered vertical slices (Approach A)** — migrate one layer at a time with a smoke gate after each phase. Preferred over Filament-first or compatibility-shim approaches because it matches the runnable constraint and avoids new technical debt.

## Target stack

- PHP `^8.2` (runtime may be 8.4)
- Laravel `^12.x`
- Filament `^4.x` **stable** (no `@beta` constraint on `filament/*`)
- Existing plugins retained when compatible: Shield, Breezy, Socialite, Exceptions, Apex Charts, Overlook, Excel, Impersonate, Mekaya, Language Switch
- Custom login render hooks (Google / WhatsApp) retained

## Phases

### Phase 0 — Baseline

- Record installed versions (`php artisan about`, `composer show filament/filament`)
- Smoke: `/admin` login, ticket list opens
- No code changes required beyond optional notes

**Gate:** App works before any standardization commit.

### Phase 1 — Composer / Filament stable

- Change `filament/filament` (and related `filament/*` if pinned) from `@beta` to stable constraint
- Run `composer update` within Filament 4.x; resolve plugin conflicts by pinning the newest **compatible** 4.x stable, not by jumping to Filament 5
- Attempt `minimum-stability: stable`; if another required package still needs beta, leave beta and document the blocker in the phase notes / README
- Run `php artisan filament:upgrade` as needed

**Gate:** Composer resolves; panel boots; Mekaya + login page render.

### Phase 2 — Laravel 12 application skeleton

- Replace legacy `bootstrap/app.php` Application singleton + Kernel bindings with `Illuminate\Foundation\Application::configure()` pattern:
  - routing (`web`, `api`, `console`, channels as today)
  - middleware aliases / groups ported 1:1 from `app/Http/Kernel.php`
  - exceptions ported from `app/Exceptions/Handler.php` (preserve WhatsApp/API render behavior)
- Add `bootstrap/providers.php` and register app providers including `App\Providers\Filament\AdminPanelProvider`
- Retire `RouteServiceProvider` route loading; relocate `HOME` (or equivalent) so `RedirectIfAuthenticated` keeps working
- Keep middleware **classes** under `app/Http/Middleware`; remove obsolete Kernel wiring only after parity
- Do **not** change public route URLs or API contracts unless skeleton migration forces a mechanical move

**Gate:** Existing web + API routes respond; Filament auth still works; no intentional behavior change.

### Phase 3 — Filament resource API cleanup

Order (small → large to keep runnable):

1. `TicketStatusResource` / `ProblemCategoryResource` / `UnitResource` / `BusinessEntityResource`
2. `UserResource` (+ relation managers)
3. `TicketResource` (+ relation managers) last

Rules when touching a file:

- Prefer Filament 4 Schema APIs already in use (`Filament\Schemas\Schema`, `Section`, etc.)
- Replace:
  - `TagsColumn` → `TextColumn` badge / list equivalent for F4
  - `MultiSelect` → `Select::make()->multiple()`
  - `reactive()` → `live()`
  - Prefer `Get` / `Set` over untyped `callable $get/$set` when editing those closures
- Table/header actions use `Filament\Actions`
- Do not restructure domain models, policies, or business rules while cleaning APIs
- One resource (or tightly related group) per commit/slice when practical

**Gate per slice:** List + create/edit smoke for that resource.

### Phase 4 — Auth, pages, widgets, panel hygiene

- Review `AdminPanelProvider` for F4-stable plugin configuration only (no branding reset)
- Keep Breezy `MyProfile`, Overlook, Shield, Apex Charts, Exceptions
- Keep login render hook + Google/WhatsApp entry points
- Touch `PhoneLogin` / auth concerns only if broken by upgrade
- Vendor view overrides under `resources/views/vendor/**`: **do not delete**; patch only if broken after Phase 1/2

**Gate:** Login (password + configured social/phone paths), profile, dashboard widgets load.

### Phase 5 — Docs & config hygiene

- Update README stack requirements to Laravel 12 + Filament 4
- Remove or annotate obsolete config comments that claim old Filament versions
- Note any remaining `minimum-stability: beta` exception if still required

**Gate:** Docs match installed stack.

## Error handling & compatibility

- Preserve custom exception rendering for API/WhatsApp integrations during Handler → `withExceptions()` move
- If a plugin fails on the newest Filament 4.x stable, pin Filament to the highest version that satisfies all plugins and record the pin reason
- If Mekaya breaks, fix Mekaya/theme integration first; do not rip out the theme

## Testing strategy

Not a greenfield TDD feature. Verification is **phase gates**:

1. `composer` / `php artisan about` healthy
2. Manual smoke: admin login, navigation, resource CRUD for touched area
3. Run existing PHPUnit tests that cover touched areas (e.g. ticket close / WhatsApp) when those areas change
4. No requirement to add a large new E2E suite solely for standardization

## Rollback

- One phase ≈ one (or few) focused commits
- Revert the failing phase commit(s) if a gate fails
- No force-push; no amend unless explicitly requested later

## Definition of Done

- [ ] `filament/*` constraints are stable 4.x (no `@beta` on Filament packages)
- [ ] App bootstrap follows Laravel 12 `Application::configure` + `bootstrap/providers.php`
- [ ] Targeted legacy Filament APIs removed from `app/Filament` (`TagsColumn`, `MultiSelect`, `reactive()`, and equivalents found during work)
- [ ] Mekaya + custom login hooks + Breezy profile still work
- [ ] Smoke gates pass: login, ticket list/create/edit, Shield nav, profile
- [ ] README reflects Laravel 12 + Filament 4

## Non-goals

- Filament 5 upgrade
- Replacing Mekaya or redesigning admin UI
- New helpdesk/WhatsApp business features
- Broad policy/domain refactors unrelated to framework standards
