<?php

namespace App\Providers;

use App\Filament\Auth\Pages\PhoneLogin;
use App\Services\Integrations\HelpdeskMcpConfiguration;
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
    }
}
