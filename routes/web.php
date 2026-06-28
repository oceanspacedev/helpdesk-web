<?php

use App\Http\Controllers\Auth\PhoneOtpLoginController;
use App\Http\Controllers\Auth\SocialiteController;
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

Route::get('/phone-login', [PhoneOtpLoginController::class, 'showPhoneForm'])->name('phone-login');
Route::post('/phone-login/send', [PhoneOtpLoginController::class, 'sendOtp'])->name('phone-login.send');
Route::get('/phone-login/verify', [PhoneOtpLoginController::class, 'showVerifyForm'])->name('phone-login.verify');
Route::post('/phone-login/verify', [PhoneOtpLoginController::class, 'verifyOtp'])->name('phone-login.verify.submit');

// socialite login
Route::get('/auth/{provider}', [SocialiteController::class, 'redirectToProvider']);
Route::get('/auth/{provider}/callback', [SocialiteController::class, 'handleProvideCallback']);
