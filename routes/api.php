<?php

use App\Http\Controllers\Api\DashboardTicketController;
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

// Feed tiket read-only untuk dashboard luar (layar TV divisi IT).
// Kontrak: docs/integrations/dashboard-api.md.
Route::prefix('dashboard')
    ->middleware('helpdesk.dashboard')
    ->withoutMiddleware('throttle:api')
    ->name('dashboard.')
    ->group(function (): void {
        Route::middleware('throttle:dashboard')->group(function (): void {
            Route::get('/tickets', [DashboardTicketController::class, 'index'])->name('tickets.index');
            Route::get('/tickets/summary', [DashboardTicketController::class, 'summary'])->name('tickets.summary');
            Route::get('/tickets/{ticket}', [DashboardTicketController::class, 'show'])
                ->whereNumber('ticket')
                ->name('tickets.show');
        });

        Route::get('/tickets/stream', [DashboardTicketController::class, 'stream'])
            ->middleware('throttle:dashboard-stream')
            ->name('tickets.stream');
    });
