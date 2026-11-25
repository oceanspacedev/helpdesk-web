<?php

use App\Http\Controllers\Api\IntegrationEventController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// Webhook sistem eksternal (kontrak docs/integrations/README.md bagian 4).
// Autentikasi lewat tanda tangan HMAC, bukan sesi atau token pengguna.
Route::post('/integrations/{integration}/events', [IntegrationEventController::class, 'store'])
    ->whereNumber('integration')
    ->withoutMiddleware('throttle:api')
    ->middleware('throttle:integration-events')
    ->name('integrations.events');
