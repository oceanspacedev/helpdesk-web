<?php

namespace App\Providers;

use App\Filament\Auth\Pages\EmailLogin;
use App\Filament\Auth\Pages\PhoneLogin;
use App\Models\Ticket;
use App\Models\User;
use App\Policies\ActivityPolicy;
use App\Policies\ExceptionPolicy;
use App\Policies\PermissionPolicy;
use App\Policies\RolePolicy;
use App\Services\Integrations\HelpdeskMcpConfiguration;
use App\Services\Integrations\IntegrationConnector;
use App\Services\Integrations\PublicReporterIdentityService;
use App\Support\ObservabilityAccess;
use BezhanSalleh\FilamentExceptions\Models\Exception;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(HelpdeskMcpConfiguration::class);
        // Satu daftar kategori per request untuk wizard "Hubungkan sistem".
        $this->app->scoped(IntegrationConnector::class);
    }

    protected function registerAuthorization(): void
    {
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(Permission::class, PermissionPolicy::class);
        Gate::policy(Activity::class, ActivityPolicy::class);
        Gate::policy(Exception::class, ExceptionPolicy::class);

        Gate::before(function (User $user, string $ability, array $arguments = []): ?bool {
            if (! $user->isSuperAdmin()) {
                return null;
            }

            // Tiket eksternal dikelola sistem tujuan (kontrak §5): Super Admin
            // pun tidak boleh mengubah/memindah/memproses dari helpdesk, jadi
            // keputusan diserahkan ke TicketPolicy.
            $subject = $arguments[0] ?? null;

            if ($subject instanceof Ticket
                && $subject->isExternal()
                && in_array($ability, ['update', 'process'], true)) {
                return null;
            }

            return true;
        });
    }

    public function boot(): void
    {
        Livewire::component('phone-login', PhoneLogin::class);
        Livewire::component('email-login', EmailLogin::class);
        ObservabilityAccess::register();
        $this->registerAuthorization();

        View::prependNamespace('filament-panels', resource_path('views/vendor/filament-panels'));

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Webhook sistem eksternal bisa mengirim banyak event sekaligus
        // (mis. setelah retry); batasnya per integrasi dan per IP.
        RateLimiter::for('integration-events', function (Request $request) {
            return Limit::perMinute(600)->by('integration-events:'.(string) $request->route('integration').':'.$request->ip());
        });

        RateLimiter::for('mcp', function (Request $request) {
            $perMinute = app(HelpdeskMcpConfiguration::class)->rateLimitPerMinute();
            $token = (string) $request->bearerToken();

            return [
                Limit::perMinute($perMinute)->by('mcp-token:'.($token !== '' ? sha1($token) : $request->ip())),
                Limit::perMinute($perMinute * 2)->by('mcp-ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('mcp-pre-auth', function (Request $request) {
            return [
                Limit::perSecond(100)->by('mcp-pre-auth-second:'.$request->ip()),
                Limit::perMinute(1200)->by('mcp-pre-auth-minute:'.$request->ip()),
            ];
        });

        RateLimiter::for('public-ticket-submit', function (Request $request) {
            $deviceToken = (string) $request->cookie(PublicReporterIdentityService::COOKIE_NAME, '');
            $deviceKey = preg_match('/^[A-Za-z0-9]{64}$/D', $deviceToken)
                ? hash('sha256', $deviceToken)
                : hash('sha256', (string) $request->ip()."\0".(string) $request->userAgent());

            return [
                Limit::perMinute(3)->by('public-ticket-device:'.$deviceKey),
                Limit::perMinute(30)->by('public-ticket-ip:'.$request->ip()),
            ];
        });

        // Definisi form sistem eksternal untuk form publik; dimuat setiap
        // pelapor memilih kategori unit berintegrasi.
        RateLimiter::for('public-external-form', function (Request $request) {
            $deviceToken = (string) $request->cookie(PublicReporterIdentityService::COOKIE_NAME, '');
            $deviceKey = preg_match('/^[A-Za-z0-9]{64}$/D', $deviceToken)
                ? hash('sha256', $deviceToken)
                : hash('sha256', (string) $request->ip()."\0".(string) $request->userAgent());

            return [
                Limit::perMinute(30)->by('public-external-form-device:'.$deviceKey),
                Limit::perMinute(120)->by('public-external-form-ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('public-reporter-identify', function (Request $request) {
            $clientKey = hash('sha256', (string) $request->ip()."\0".(string) $request->userAgent());

            return [
                Limit::perMinute(10)->by('public-reporter-client:'.$clientKey),
                Limit::perHour(120)->by('public-reporter-ip:'.$request->ip()),
            ];
        });
    }
}
