<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Integrations\WhatsappHelpdeskClassificationResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhatsappHelpdeskValidateClassificationController extends Controller
{
    public function __invoke(Request $request, WhatsappHelpdeskClassificationResolver $resolver): JsonResponse
    {
        $field = (string) $request->input('field', '');
        $value = $request->input('value');
        $ticketContext = is_array($request->input('ticket_context')) ? $request->input('ticket_context') : [];
        $actor = is_array($request->input('actor')) ? $request->input('actor') : [];
        $owner = $this->resolveOwner($actor);

        $result = $resolver->validateField($field, $value, $ticketContext, $owner);

        if (! $result['ok']) {
            return response()->json([
                'ok' => false,
                'status' => 'validation_error',
                'result_status' => 'validation_error',
                'message' => $result['message'],
                'next_question' => $resolver->nextQuestionForField($result['field']),
                'data' => [
                    'missing_fields' => [$result['field']],
                    'field_errors' => [[
                        'field' => $result['field'],
                        'reason' => $result['reason'],
                        'provided_value' => $result['provided_value'],
                    ]],
                    'form_options' => $result['form_options'],
                    'next_field' => $result['next_field'],
                ],
                'count' => 0,
                'error' => [
                    'code' => $result['reason'] === 'not_found' ? 'HELPDESK_VALUE_NOT_FOUND' : 'HELPDESK_FORM_INCOMPLETE',
                    'message' => $result['message'],
                ],
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'status' => 'validated',
            'result_status' => 'validated',
            'message' => $result['message'],
            'next_question' => $resolver->nextQuestionForField((string) $result['next_field']),
            'data' => [
                'resolved' => $result['resolved'],
                'form_options' => $result['form_options'],
                'next_field' => $result['next_field'],
            ],
            'count' => 1,
            'error' => null,
        ]);
    }

    private function resolveOwner(array $actor): ?User
    {
        $phone = preg_replace('/\D+/', '', (string) ($actor['phone'] ?? $actor['identifier'] ?? data_get($actor, 'metadata.phone') ?? data_get($actor, 'metadata.sender_phone') ?? ''));
        if ($phone === '' || str_contains(strtolower((string) ($actor['phone'] ?? $actor['identifier'] ?? '')), '@lid')) {
            return null;
        }

        if (str_starts_with($phone, '0')) {
            $phone = '62'.substr($phone, 1);
        } elseif (str_starts_with($phone, '8')) {
            $phone = '62'.$phone;
        }

        return User::query()->where('phone', $phone)->where('is_active', true)->first();
    }
}
