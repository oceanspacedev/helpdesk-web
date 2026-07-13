<?php

namespace App\Providers;

use App\Filament\Auth\Pages\PhoneLogin;
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
        //
    }

    public function boot(): void
    {
        Livewire::component('phone-login', PhoneLogin::class);

        View::prependNamespace('filament-panels', resource_path('views/vendor/filament-panels'));

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
    }
}
