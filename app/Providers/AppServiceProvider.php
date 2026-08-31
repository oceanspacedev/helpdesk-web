<?php

namespace App\Providers;

use App\Filament\Auth\Pages\PhoneLogin;
use App\Services\Integrations\HelpdeskMcpConfiguration;
use App\Services\Integrations\PublicReporterIdentityService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public const HOME = '/home';

    public function register(): void
    {
        $this->app->scoped(HelpdeskMcpConfiguration::class);
    }

    public function boot(): void
    {
        Livewire::component('phone-login', PhoneLogin::class);

        View::prependNamespace('filament-panels', resource_path('views/vendor/filament-panels'));

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
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

        RateLimiter::for('public-reporter-identify', function (Request $request) {
            $clientKey = hash('sha256', (string) $request->ip()."\0".(string) $request->userAgent());

            return [
                Limit::perMinute(10)->by('public-reporter-client:'.$clientKey),
                Limit::perHour(120)->by('public-reporter-ip:'.$request->ip()),
            ];
        });
    }
}
