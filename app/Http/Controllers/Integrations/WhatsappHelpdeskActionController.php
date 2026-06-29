<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Services\Integrations\WhatsappHelpdeskActionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhatsappHelpdeskActionController extends Controller
{
    public function __invoke(Request $request, WhatsappHelpdeskActionService $service): JsonResponse
    {
        $result = $service->handle($request->all());

        return response()->json($result['body'], $result['http_status']);
    }
}
