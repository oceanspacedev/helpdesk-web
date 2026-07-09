# Filament 4 + Laravel 12 Standardization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Standardize this helpdesk app onto Laravel 12 skeleton + Filament 4 stable while keeping Mekaya/custom views and remaining runnable after every phase.

**Architecture:** Layered vertical slices — (1) pin Filament 4 stable, (2) migrate bootstrap/providers/middleware/exceptions to Laravel 12 patterns with 1:1 behavior, (3) replace deprecated Filament APIs resource-by-resource, (4) panel/auth hygiene only if broken, (5) docs. No business-feature changes.

**Tech Stack:** PHP ^8.2, Laravel ^12, Filament ^4 stable, existing plugins (Shield, Breezy, Socialite, Exceptions, Apex Charts, Overlook, Excel, Impersonate, Mekaya), PHPUnit for existing feature/unit tests.

**Spec:** `docs/superpowers/specs/2026-07-09-filament4-laravel12-standardization-design.md`

---

## File map

| File | Responsibility |
|------|----------------|
| `composer.json` / `composer.lock` | Filament 4 stable constraints, stability |
| `bootstrap/app.php` | Laravel 12 `Application::configure` (routing, middleware, exceptions) |
| `bootstrap/providers.php` | App provider registration |
| `public/index.php` | Request handling via Application (no Http Kernel) |
| `artisan` | Command handling via Application |
| `tests/CreatesApplication.php` | Bootstrap app for tests |
| `config/app.php` | Remove legacy `providers` array after move |
| `app/Providers/AppServiceProvider.php` | Keep Mekaya view namespace; host `HOME` constant; API rate limiter |
| `app/Providers/RouteServiceProvider.php` | Delete after migration |
| `app/Http/Kernel.php` | Delete after middleware port |
| `app/Console/Kernel.php` | Delete after schedule/commands port |
| `app/Exceptions/Handler.php` | Delete after exceptions port |
| `app/Http/Middleware/RedirectIfAuthenticated.php` | Point `HOME` at `AppServiceProvider` |
| `app/Filament/Resources/UserResource.php` | Replace `MultiSelect` / `TagsColumn` |
| `app/Filament/Resources/UnitResource/RelationManagers/UsersRelationManager.php` | Replace `TagsColumn` |
| `app/Filament/Resources/TicketResource.php` | Replace `reactive()` + typed `Get`/`Set` |
| `app/Providers/Filament/AdminPanelProvider.php` | Touch only if plugin config breaks |
| `README.md` | Document Laravel 12 + Filament 4 |

Do **not** delete `resources/views/vendor/**` unless a specific override is proven broken and replaced with an equivalent fix.

---

### Task 1: Phase 0 — Baseline smoke

**Files:**
- None (read-only)

- [ ] **Step 1: Record versions**

Run:

```bash
php artisan about
composer show filament/filament | rg -i '^(name|versions|licenses)'
```

Expected: Laravel 12.x, Filament currently `v4.12.0-beta2` (or similar beta).

- [ ] **Step 2: Confirm app boots**

Run:

```bash
php artisan route:list --path=admin | head -20
php artisan test --filter=ExampleTest
```

Expected: admin routes listed; Example tests pass (or skip only if env/DB missing — note blocker).

- [ ] **Step 3: Manual smoke note**

Open `/admin` in browser (or document that login smoke will be done after Phase 1). Record any known pre-existing breakage so it is not blamed on later phases.

- [ ] **Step 4: Commit optional baseline note only if you created a file**

If no files changed, skip commit.

---

### Task 2: Phase 1 — Pin Filament 4 stable

**Files:**
- Modify: `composer.json`
- Modify: `composer.lock` (via composer)

- [ ] **Step 1: Update Filament constraints in `composer.json`**

Change require entries from beta pins to stable:

```json
"filament/filament": "^4.11",
"filament/infolists": "^4.11",
```

Also set:

```json
"minimum-stability": "stable",
"prefer-stable": true
```

If `composer update` later fails because another required package needs beta, revert only `minimum-stability` to `"beta"`, keep Filament constraints without `@beta`, and document the blocker in the commit message / README later (Task 10).

- [ ] **Step 2: Update lockfile within Filament 4.x**

Run:

```bash
composer update filament/filament filament/infolists filament/forms filament/tables filament/actions filament/schemas filament/support filament/widgets filament/notifications filament/query-builder --with-all-dependencies
```

Expected: resolves to Filament `v4.11.x` or newer **stable** 4.x (not beta, not 5.x).

If plugins conflict, pin Filament to the highest 4.x stable that satisfies all plugins, e.g.:

```bash
composer require filament/filament:^4.11 filament/infolists:^4.11 --with-all-dependencies
```

Do **not** upgrade to Filament 5.

- [ ] **Step 3: Upgrade Filament assets**

Run:

```bash
php artisan filament:upgrade
php artisan about
composer show filament/filament | rg -i '^versions'
```

Expected: Filament section present; version is stable 4.x.

- [ ] **Step 4: Run existing tests that should still pass**

Run:

```bash
php artisan test --filter='AdminRegistrationDisabledTest|SocialiteRegistrationDisabledTest|PhoneOtpLoginTest'
```

Expected: PASS (or document env-only failures).

- [ ] **Step 5: Commit**

```bash
git add composer.json composer.lock
git commit -m "$(cat <<'EOF'
chore: pin Filament 4 to stable constraints

Drop @beta pins so the admin stack tracks Filament 4 stable releases compatible with existing plugins.
EOF
)"
```

---

### Task 3: Phase 2a — Add Laravel 12 bootstrap skeleton (keep old Kernels temporarily)

**Files:**
- Create: `bootstrap/providers.php`
- Modify: `bootstrap/app.php`
- Modify: `public/index.php`
- Modify: `artisan`
- Modify: `tests/CreatesApplication.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `app/Http/Middleware/RedirectIfAuthenticated.php`
- Modify: `config/app.php`
- Delete (later in Task 4): Kernels / Handler / RouteServiceProvider

This task introduces the new bootstrap **and** ports middleware/exceptions/providers in one runnable cut. Keep Mekaya view prepend.

- [ ] **Step 1: Create `bootstrap/providers.php`**

```php
<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\AuthServiceProvider::class,
    App\Providers\EventServiceProvider::class,
    App\Providers\Filament\AdminPanelProvider::class,
];
```

Note: `RouteServiceProvider` is intentionally omitted; routing moves into `bootstrap/app.php`.

- [ ] **Step 2: Replace `bootstrap/app.php` with Application::configure**

```php
<?php

use App\Providers\AppServiceProvider;
use BezhanSalleh\FilamentExceptions\FilamentExceptions;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Throwable;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->use([
            // \App\Http\Middleware\TrustHosts::class,
            \App\Http\Middleware\TrustProxies::class,
            \Illuminate\Http\Middleware\HandleCors::class,
            \App\Http\Middleware\PreventRequestsDuringMaintenance::class,
            \Illuminate\Foundation\Http\Middleware\ValidatePostSize::class,
            \App\Http\Middleware\TrimStrings::class,
            \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
        ]);

        $middleware->web(replace: [
            \Illuminate\Cookie\Middleware\EncryptCookies::class => \App\Http\Middleware\EncryptCookies::class,
            \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class => \App\Http\Middleware\VerifyCsrfToken::class,
        ]);

        $middleware->alias([
            'auth' => \App\Http\Middleware\Authenticate::class,
            'auth.basic' => \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class,
            'auth.session' => \Illuminate\Session\Middleware\AuthenticateSession::class,
            'cache.headers' => \Illuminate\Http\Middleware\SetCacheHeaders::class,
            'can' => \Illuminate\Auth\Middleware\Authorize::class,
            'guest' => \App\Http\Middleware\RedirectIfAuthenticated::class,
            'password.confirm' => \Illuminate\Auth\Middleware\RequirePassword::class,
            'signed' => \App\Http\Middleware\ValidateSignature::class,
            'throttle' => \Illuminate\Routing\Middleware\ThrottleRequests::class,
            'verified' => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->reportable(function (Throwable $e): bool {
            if (app(\Illuminate\Contracts\Debug\ExceptionHandler::class)->shouldReport($e)) {
                FilamentExceptions::report($e);
            }

            return true;
        });

        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request) {
            if (! $request->is('api/integrations/whatsapp/*')) {
                return null;
            }

            $message = 'Metode HTTP tidak didukung untuk endpoint integrasi WhatsApp.';

            return response()->json([
                'ok' => false,
                'status' => 'validation_error',
                'result_status' => 'validation_error',
                'message' => $message,
                'data' => null,
                'count' => 0,
                'error' => [
                    'code' => 'method_not_allowed',
                    'message' => $message,
                    'allowed_methods' => $e->getHeaders()['Allow'] ?? null,
                ],
            ], 405);
        });
    })
    ->create();
```

**Important implementation note:** Laravel 12 auto-loads `bootstrap/providers.php`. Do **not** invent a custom `withProviders(require ...)` call unless the installed framework requires it. Confirm with:

```bash
rg -n "providers.php|withProviders" vendor/laravel/framework/src/Illuminate/Foundation -g '*.php' | head -20
```

Keep the middleware + exception bodies above; only adjust the Application builder chaining to match the installed Laravel 12 API.

Also update `AppServiceProvider`:

```php
<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public const HOME = '/home';

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        View::prependNamespace('filament-panels', resource_path('views/vendor/filament-panels'));

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
    }
}
```

Preserve `HOME = '/home'` for parity with old `RouteServiceProvider::HOME`.

- [ ] **Step 3: Update `RedirectIfAuthenticated`**

```php
<?php

namespace App\Http\Middleware;

use App\Providers\AppServiceProvider;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfAuthenticated
{
    public function handle(Request $request, Closure $next, string ...$guards): Response
    {
        $guards = empty($guards) ? [null] : $guards;

        foreach ($guards as $guard) {
            if (Auth::guard($guard)->check()) {
                return redirect(AppServiceProvider::HOME);
            }
        }

        return $next($request);
    }
}
```

- [ ] **Step 4: Update `public/index.php` to Laravel 12 style**

```php
<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

require __DIR__.'/../vendor/autoload.php';

/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
```

- [ ] **Step 5: Update `artisan` to Laravel 12 style**

```php
#!/usr/bin/env php
<?php

use Illuminate\Foundation\Application;
use Symfony\Component\Console\Input\ArgvInput;

define('LARAVEL_START', microtime(true));

require __DIR__.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once __DIR__.'/bootstrap/app.php';

$status = $app->handleCommand(new ArgvInput);

exit($status);
```

- [ ] **Step 6: Update `tests/CreatesApplication.php`**

```php
<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

trait CreatesApplication
{
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
```

If Laravel 12 no longer binds `Illuminate\Contracts\Console\Kernel` the same way after Kernel deletion, switch bootstrap to:

```php
$app->make(\Illuminate\Foundation\Console\Kernel::class)->bootstrap();
```

or the framework-recommended test bootstrap for the installed version. Verify with `php artisan test --filter=ExampleTest`.

- [ ] **Step 7: Remove providers array from `config/app.php`**

Delete the entire `'providers' => ServiceProvider::defaultProviders()->merge([...])->toArray(),` block (and unused `ServiceProvider` import if present). Providers now come from `bootstrap/providers.php`.

Keep `'aliases'` as-is unless the installed Laravel 12 skeleton already removed it.

- [ ] **Step 8: Verify boot**

Run:

```bash
php artisan about
php artisan route:list --path=admin | head -20
php artisan route:list --path=api | head -20
php artisan test --filter='ExampleTest|WhatsappHelpdeskActionTest|PhoneOtpLoginTest'
```

Expected: app boots; admin + api routes present; WhatsApp method-not-allowed JSON behavior still covered by feature tests if present.

- [ ] **Step 9: Commit**

```bash
git add bootstrap/app.php bootstrap/providers.php public/index.php artisan tests/CreatesApplication.php app/Providers/AppServiceProvider.php app/Http/Middleware/RedirectIfAuthenticated.php config/app.php
git commit -m "$(cat <<'EOF'
refactor: migrate application bootstrap to Laravel 12 style

Move routing, middleware, providers, and exception handling into Application::configure while preserving Filament and WhatsApp API behavior.
EOF
)"
```

---

### Task 4: Phase 2b — Remove obsolete Kernel / Handler / RouteServiceProvider

**Files:**
- Delete: `app/Http/Kernel.php`
- Delete: `app/Console/Kernel.php`
- Delete: `app/Exceptions/Handler.php`
- Delete: `app/Providers/RouteServiceProvider.php`

- [ ] **Step 1: Ensure nothing still references deleted classes**

Run:

```bash
rg -n "Http\\\\Kernel|Console\\\\Kernel|Exceptions\\\\Handler|RouteServiceProvider" app bootstrap config routes tests --glob '*.php'
```

Expected: no remaining references (except possibly vendor). Fix any hits before deleting.

- [ ] **Step 2: Delete the four obsolete files**

```bash
rm app/Http/Kernel.php app/Console/Kernel.php app/Exceptions/Handler.php app/Providers/RouteServiceProvider.php
```

- [ ] **Step 3: Re-verify boot + tests**

Run:

```bash
php artisan about
php artisan test --filter='WhatsappHelpdeskActionTest|AdminRegistrationDisabledTest|PhoneOtpLoginTest'
```

Expected: PASS / app healthy.

- [ ] **Step 4: Commit**

```bash
git add -u app/Http/Kernel.php app/Console/Kernel.php app/Exceptions/Handler.php app/Providers/RouteServiceProvider.php
git commit -m "$(cat <<'EOF'
refactor: remove legacy Laravel HTTP/Console kernels and route provider

These responsibilities now live in bootstrap/app.php and AppServiceProvider under the Laravel 12 application structure.
EOF
)"
```

---

### Task 5: Phase 3a — Clean small Filament resources (scan + no-op if clean)

**Files:**
- Modify only if legacy APIs found:
  - `app/Filament/Resources/TicketStatusResource.php`
  - `app/Filament/Resources/ProblemCategoryResource.php`
  - `app/Filament/Resources/UnitResource.php`
  - `app/Filament/Resources/BusinessEntityResource.php`
  - their `Pages/` and `RelationManagers/`

- [ ] **Step 1: Scan for targeted legacy APIs**

Run:

```bash
rg -n "TagsColumn|MultiSelect|->reactive\(|Forms\\\\Form|Infolists\\\\Infolist" app/Filament/Resources/TicketStatusResource.php app/Filament/Resources/ProblemCategoryResource.php app/Filament/Resources/UnitResource.php app/Filament/Resources/BusinessEntityResource.php app/Filament/Resources/TicketStatusResource app/Filament/Resources/ProblemCategoryResource app/Filament/Resources/UnitResource app/Filament/Resources/BusinessEntityResource --glob '*.php'
```

- [ ] **Step 2: Fix any hits using the replacement rules**

Replacements:

```php
// TagsColumn → TextColumn badge
Tables\Columns\TextColumn::make('roles.name')->badge();

// MultiSelect → Select multiple
Forms\Components\Select::make('units')
    ->multiple()
    ->relationship('units', 'name')
    ->searchable();

// reactive → live
->live()
```

If the scan is empty for these resources, skip code edits.

- [ ] **Step 3: Verify no legacy APIs remain in this slice**

Run:

```bash
rg -n "TagsColumn|MultiSelect|->reactive\(" app/Filament/Resources/TicketStatusResource.php app/Filament/Resources/ProblemCategoryResource.php app/Filament/Resources/UnitResource.php app/Filament/Resources/BusinessEntityResource.php app/Filament/Resources/UnitResource --glob '*.php'
```

Expected: no matches in these small resources (Unit relation manager may still have TagsColumn until Task 6/7 — UnitResource itself should be clean; `UsersRelationManager` is handled in Task 6).

- [ ] **Step 4: Commit only if files changed**

```bash
git add app/Filament/Resources
git commit -m "$(cat <<'EOF'
refactor: align small Filament resources with Filament 4 APIs

Replace deprecated form/table helpers so master-data resources follow current Filament 4 patterns.
EOF
)"
```

---

### Task 6: Phase 3b — UserResource + Unit users relation manager

**Files:**
- Modify: `app/Filament/Resources/UserResource.php`
- Modify: `app/Filament/Resources/UnitResource/RelationManagers/UsersRelationManager.php`

- [ ] **Step 1: Replace `MultiSelect` and `TagsColumn` in `UserResource`**

In `form()`:

```php
Forms\Components\Select::make('units')
    ->relationship('units', 'name')
    ->options(Unit::all()->pluck('name', 'id'))
    ->multiple()
    ->searchable(),
```

In `table()` columns:

```php
Tables\Columns\TextColumn::make('name')->searchable(),
Tables\Columns\TextColumn::make('email'),
Tables\Columns\TextColumn::make('roles.name')->badge(),
Tables\Columns\TextColumn::make('units.name')->badge(),
Tables\Columns\IconColumn::make('is_active')->boolean(),
```

Do not change Impersonate action, filters, pages, or relations.

- [ ] **Step 2: Replace `TagsColumn` in `UsersRelationManager`**

```php
Tables\Columns\TextColumn::make('name'),
Tables\Columns\TextColumn::make('roles.name')->badge(),
```

- [ ] **Step 3: Verify scan clean for these files**

Run:

```bash
rg -n "TagsColumn|MultiSelect|->reactive\(" app/Filament/Resources/UserResource.php app/Filament/Resources/UnitResource/RelationManagers/UsersRelationManager.php
php artisan about
```

Expected: no matches; app still boots.

- [ ] **Step 4: Commit**

```bash
git add app/Filament/Resources/UserResource.php app/Filament/Resources/UnitResource/RelationManagers/UsersRelationManager.php
git commit -m "$(cat <<'EOF'
refactor: replace deprecated Filament APIs in user resources

Use Select::multiple() and TextColumn badges instead of MultiSelect/TagsColumn.
EOF
)"
```

---

### Task 7: Phase 3c — TicketResource live/Get/Set cleanup

**Files:**
- Modify: `app/Filament/Resources/TicketResource.php`

- [ ] **Step 1: Add imports for Get/Set**

At top of `TicketResource.php` ensure:

```php
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
```

- [ ] **Step 2: Replace unit_id reactive closure**

Find the `unit_id` Select and change to:

```php
Forms\Components\Select::make('unit_id')
    ->label(__('Work Unit'))
    ->options(Unit::all()->pluck('name', 'id'))
    ->searchable()
    ->required()
    ->live()
    ->afterStateUpdated(function ($state, Get $get, Set $set): void {
        $unit = Unit::find($state);
        if ($unit) {
            $problemCategoryId = (int) $get('problem_category_id');
            if ($problemCategoryId && $problemCategory = ProblemCategory::find($problemCategoryId)) {
                if ($problemCategory->unit_id !== $unit->id) {
                    $set('problem_category_id', null);
                }
            }
        }
    }),
```

- [ ] **Step 3: Update problem_category options closure to typed Get**

```php
Forms\Components\Select::make('problem_category_id')
    ->label(__('Problem Category'))
    ->options(function (Get $get): array {
        $unit = Unit::find($get('unit_id'));
        if ($unit) {
            return $unit->problemCategories->pluck('name', 'id')->all();
        }

        return ProblemCategory::all()->pluck('name', 'id')->all();
    })
    ->searchable()
    ->required(),
```

Only change closures that already used `callable $get/$set` while editing this area. Do not rewrite unrelated Ticket business logic.

- [ ] **Step 4: Scan TicketResource for remaining targets**

Run:

```bash
rg -n "TagsColumn|MultiSelect|->reactive\(|callable \$get|callable \$set" app/Filament/Resources/TicketResource.php
```

Expected: no `TagsColumn` / `MultiSelect` / `reactive()`. Remaining `callable $get/$set` elsewhere in the file may be cleaned in the same commit **only if** touched while editing adjacent code; do not expand into a full Ticket rewrite.

- [ ] **Step 5: Run ticket-related tests**

Run:

```bash
php artisan test --filter='WhatsappHelpdeskActionTest'
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Filament/Resources/TicketResource.php
git commit -m "$(cat <<'EOF'
refactor: modernize TicketResource reactive form APIs for Filament 4

Switch reactive() to live() and use typed Get/Set utilities for dependent selects.
EOF
)"
```

---

### Task 8: Phase 3d — Full Filament legacy API sweep

**Files:**
- Modify: any remaining files under `app/Filament` that still match the scan

- [ ] **Step 1: Repo-wide scan**

Run:

```bash
rg -n "TagsColumn|Forms\\\\Components\\\\MultiSelect|->reactive\(" app/Filament --glob '*.php'
```

- [ ] **Step 2: Fix each remaining hit with the same replacement rules as Task 5–7**

Show the exact before/after in the working tree; do not change business rules.

- [ ] **Step 3: Confirm clean**

Run:

```bash
rg -n "TagsColumn|Forms\\\\Components\\\\MultiSelect|->reactive\(" app/Filament --glob '*.php'
```

Expected: no matches.

- [ ] **Step 4: Commit if needed**

```bash
git add app/Filament
git commit -m "$(cat <<'EOF'
refactor: finish Filament 4 deprecated API sweep in app/Filament

Remove remaining TagsColumn, MultiSelect, and reactive() usages.
EOF
)"
```

---

### Task 9: Phase 4 — Panel / auth hygiene (only if broken)

**Files:**
- Modify only if smoke fails: `app/Providers/Filament/AdminPanelProvider.php`, auth pages under `app/Filament/Auth`, `app/Filament/Pages/MyProfile.php`, vendor view overrides under `resources/views/vendor/**`

- [ ] **Step 1: Smoke checklist**

Verify manually or via feature tests:

1. `/admin/login` renders (Mekaya + Google/WhatsApp extras)
2. Password login works with a known dummy account if DB seeded
3. Breezy profile page loads when authenticated
4. Dashboard / Overlook widgets load
5. Shield resources still appear for Super Admin

Run automated coverage available:

```bash
php artisan test --filter='AdminRegistrationDisabledTest|SocialiteRegistrationDisabledTest|PhoneOtpLoginTest'
```

- [ ] **Step 2: Patch only failures**

Rules:

- Keep Mekaya plugin registration
- Keep login `PanelsRenderHook::AUTH_LOGIN_FORM_AFTER`
- Do **not** delete vendor overrides; edit the specific broken Blade/PHP only
- Prefer smallest diff

- [ ] **Step 3: Commit only if patches were required**

```bash
git add app/Providers/Filament/AdminPanelProvider.php app/Filament resources/views/vendor
git commit -m "$(cat <<'EOF'
fix: restore Filament panel auth/theme compatibility after standardization

Apply minimal patches so Mekaya, login hooks, and profile flows keep working on Filament 4 stable.
EOF
)"
```

If nothing broke, skip commit and note “Phase 4 no-op” in the final summary.

---

### Task 10: Phase 5 — Docs + final DoD check

**Files:**
- Modify: `README.md`
- Optionally note stability exception in README if `minimum-stability` remained `beta`

- [ ] **Step 1: Update README stack claims**

Replace outdated Laravel 10 / Filament v2 wording with:

```markdown
## Requirements
* PHP 8.2 or higher
* Laravel 12.x
* Filament 4.x
* Database (eg: MySQL, PostgreSQL, SQLite)
* Web Server (eg: Apache, Nginx, IIS)
```

And in the intro paragraph, change framework mentions to Laravel 12 + Filament 4.

- [ ] **Step 2: Final DoD commands**

Run:

```bash
composer show filament/filament | rg -i '^versions'
rg -n "@beta" composer.json || true
rg -n "TagsColumn|Forms\\\\Components\\\\MultiSelect|->reactive\(" app/Filament --glob '*.php' || true
test -f bootstrap/providers.php && test -f bootstrap/app.php && echo 'bootstrap ok'
php artisan about
php artisan test
```

Expected:

- Filament versions line is stable 4.x
- No `@beta` on filament packages in `composer.json`
- No targeted legacy APIs in `app/Filament`
- `bootstrap/providers.php` exists
- Test suite green (or known env failures documented)

- [ ] **Step 3: Commit docs**

```bash
git add README.md
git commit -m "$(cat <<'EOF'
docs: update README for Laravel 12 and Filament 4

Align project documentation with the standardized application stack.
EOF
)"
```

---

## Self-review (plan vs spec)

| Spec requirement | Task |
|------------------|------|
| Phase 0 baseline | Task 1 |
| Filament stable / no `@beta` | Task 2 |
| `minimum-stability` prefer stable | Task 2 (+ Task 10 docs if exception) |
| Laravel 12 `Application::configure` + providers | Tasks 3–4 |
| Preserve WhatsApp API exception JSON | Task 3 exceptions render |
| Preserve Mekaya / vendor views | Tasks 3, 9 (no delete) |
| Resource API cleanup order | Tasks 5–8 |
| Auth/panel hygiene | Task 9 |
| README update | Task 10 |
| Runnable per phase + commits | Each task ends with verify + commit |
| No Filament 5 / no redesign / no business features | Explicit in Tasks 2 & 9 |

Placeholder scan: none intentionally left; Step 2 of Task 3 includes an explicit “match installed Laravel 12 API” instruction because `withProviders` signature must be confirmed against the installed framework — that is an execution-time API check, not an open product TBD.
