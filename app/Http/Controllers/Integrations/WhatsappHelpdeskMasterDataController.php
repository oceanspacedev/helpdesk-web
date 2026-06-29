<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Services\Integrations\WhatsappHelpdeskFormOptionsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhatsappHelpdeskMasterDataController extends Controller
{
    public function __invoke(Request $request, WhatsappHelpdeskFormOptionsService $service): JsonResponse
    {
        $unitId = (int) $request->query('unit_id', 0);

        return response()->json([
            'ok' => true,
            'result_status' => 'found',
            'message' => 'Master data form Helpdesk.',
            'data' => [
                'form_options' => $service->formOptions($unitId > 0 ? $unitId : null),
            ],
            'count' => 1,
            'error' => null,
        ]);
    }
}
