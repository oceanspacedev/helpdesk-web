<?php

use App\Filament\Auth\Pages\EmailLogin;
use App\Filament\Auth\Pages\PhoneLogin;
use App\Http\Controllers\PublicTicketController;
use App\Http\Middleware\HandleInertiaRequests;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::controller(PublicTicketController::class)->group(function (): void {
    $publicForm = [
        'cache.headers:private;no_store;no_cache;must_revalidate',
        HandleInertiaRequests::class,
    ];

    Route::get('/', 'create')
        ->middleware($publicForm)
        ->name('public-tickets.create');
    Route::get('/lapor', 'create')
        ->middleware($publicForm)
        ->name('public-tickets.alias');
    Route::post('/lapor', 'store')
        ->middleware(['throttle:public-ticket-submit', HandleInertiaRequests::class])
        ->block(30, 10)
        ->name('public-tickets.store');

    Route::prefix('pelapor')->name('public-reporter.')->group(function (): void {
        Route::post('/identitas', 'identify')
            ->middleware(['throttle:public-reporter-identify', HandleInertiaRequests::class])
            ->block(30, 10)
            ->name('identify');
        Route::post('/ganti', 'changeReporter')
            ->middleware(['throttle:20,1', HandleInertiaRequests::class])
            ->block(30, 10)
            ->name('change');
    });
});

Route::get('/phone-login', PhoneLogin::class)
    ->middleware([
        DisableBladeIconComponents::class,
        DispatchServingFilamentEvent::class,
    ])
    ->name('phone-login');

Route::middleware([
    DisableBladeIconComponents::class,
    DispatchServingFilamentEvent::class,
])->group(function (): void {
    Route::get('/email-login', EmailLogin::class)->name('email-login');

    // Magic link dari email. Membuka URL saja tidak memakai token; lihat EmailLogin::$magicToken.
    Route::get('/email-login/link/{token}', EmailLogin::class)
        ->where('token', '[A-Za-z0-9]{64}')
        ->name('email-login.link');
});
