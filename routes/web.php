<?php

use App\Filament\Auth\Pages\PhoneLogin;
use App\Http\Controllers\Auth\PhoneOtpLoginController;
use App\Http\Controllers\Auth\SocialiteController;
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

Route::get('/', function () {
    return redirect('admin');
});

Route::get('/phone-login', PhoneLogin::class)
    ->middleware([
        DisableBladeIconComponents::class,
        DispatchServingFilamentEvent::class,
    ])
    ->name('phone-login');

Route::get('/phone-login/verify', [PhoneOtpLoginController::class, 'showVerifyForm'])->name('phone-login.verify');
Route::post('/phone-login/verify', [PhoneOtpLoginController::class, 'verifyOtp'])->name('phone-login.verify.submit');

// socialite login
Route::get('/auth/{provider}', [SocialiteController::class, 'redirectToProvider']);
Route::get('/auth/{provider}/callback', [SocialiteController::class, 'handleProvideCallback']);
