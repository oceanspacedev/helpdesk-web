<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Integrations\AiHelpdeskActionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiHelpdeskActionController extends Controller
{
    public function __invoke(Request $request, AiHelpdeskActionService $service): JsonResponse
    {
        $expectedToken = (string) config('services.ita_helpdesk.token', '');
        $providedToken = (string) (
            $request->bearerToken()
            ?: $request->header('X-ITA-Helpdesk-Token')
            ?: $request->header('X-ITA-Integration-Token')
        );

        if ($expectedToken === '') {
            return response()->json([
                'ok' => false,
                'status' => 'service_error',
                'result_status' => 'service_error',
                'message' => 'Token integrasi ITA Helpdesk belum dikonfigurasi.',
                'data' => null,
                'error' => ['code' => 'ITA_HELPDESK_TOKEN_NOT_CONFIGURED'],
            ], 503);
        }

        if (! hash_equals($expectedToken, $providedToken)) {
            return response()->json([
                'ok' => false,
                'status' => 'unauthorized',
                'result_status' => 'unauthorized',
                'message' => 'Token integrasi ITA Helpdesk tidak valid.',
                'data' => null,
                'error' => ['code' => 'INVALID_ITA_HELPDESK_TOKEN'],
            ], 401);
        }

        $result = $service->handle($request->all());

        return response()->json($result['body'], $result['http_status']);
    }
}
