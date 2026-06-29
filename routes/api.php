<?php

use App\Http\Controllers\Integrations\WhatsappHelpdeskActionController;
use App\Http\Controllers\Integrations\WhatsappHelpdeskMasterDataController;
use App\Http\Controllers\Integrations\WhatsappHelpdeskValidateClassificationController;
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

Route::prefix('integrations/whatsapp')->group(function (): void {
    Route::get('/helpdesk/master-data', WhatsappHelpdeskMasterDataController::class);
    Route::post('/helpdesk/validate-classification', WhatsappHelpdeskValidateClassificationController::class);
    Route::post('/helpdesk/actions', WhatsappHelpdeskActionController::class);
});
