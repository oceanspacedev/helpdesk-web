<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Models\IntegrationEvent;
use App\Services\Integrations\External\ExternalTicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Webhook sistem eksternal → helpdesk (kontrak v1 bagian 4).
 */
class IntegrationEventController extends Controller
{
    public const TIMESTAMP_HEADER = 'X-Helpdesk-Timestamp';

    public const SIGNATURE_HEADER = 'X-Helpdesk-Signature';

    public const TOLERANCE_SECONDS = 300;

    public function __construct(
        private readonly ExternalTicketService $tickets,
    ) {}

    public function store(Request $request, int $integration): JsonResponse
    {
        $integration = Integration::query()->find($integration);
        if (! $integration) {
            return $this->reply(404, 'not_found', 'Integrasi tidak ditemukan.');
        }

        $rawBody = (string) $request->getContent();
        if (! $this->signatureIsValid($integration, $request, $rawBody)) {
            return $this->reply(401, 'invalid_signature', 'Tanda tangan atau timestamp tidak valid.');
        }

        if (! $integration->is_active) {
            return $this->reply(403, 'inactive', 'Integrasi sedang nonaktif.');
        }

        $payload = json_decode($rawBody, true);
        $eventId = is_array($payload) && is_scalar($payload['event_id'] ?? null)
            ? trim((string) $payload['event_id'])
            : '';
        if ($eventId === '' || mb_strlen($eventId) > 191 || ! is_array($payload['data'] ?? null)) {
            return $this->reply(422, 'invalid_payload', 'event_id dan data wajib ada.');
        }

        $type = is_string($payload['type'] ?? null) ? $payload['type'] : '';
        $ref = is_scalar(data_get($payload, 'data.ref')) ? trim((string) data_get($payload, 'data.ref')) : '';

        DB::beginTransaction();

        try {
            $inserted = IntegrationEvent::query()->insertOrIgnore([
                'integration_id' => $integration->id,
                'event_id' => $eventId,
                'received_at' => now(),
            ]);
            if ($inserted === 0) {
                DB::commit();

                return $this->reply(200, 'duplicate', 'Event sudah pernah diproses.');
            }

            if ($type !== 'ticket.updated') {
                DB::commit();

                return $this->reply(200, 'ignored', 'Tipe event tidak ditangani.');
            }

            $ticket = $ref !== '' ? $this->tickets->findByRef($integration, $ref) : null;
            if (! $ticket) {
                // Webhook bisa tiba sebelum tiket lokal selesai dibuat. Event
                // tidak dicatat agar retry sistem eksternal tetap diproses.
                DB::rollBack();

                return $this->reply(404, 'ticket_not_found', 'Tiket dengan ref tersebut belum dikenal helpdesk.');
            }

            if ($ticket->trashed()) {
                DB::commit();

                return $this->reply(200, 'ignored', 'Tiket sudah dihapus di helpdesk.');
            }

            $applied = $this->tickets->applySnapshot($ticket, $payload['data']);
            DB::commit();
        } catch (InvalidArgumentException $exception) {
            DB::rollBack();

            return $this->reply(422, 'invalid_snapshot', $exception->getMessage());
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        return $applied
            ? $this->reply(200, 'processed', 'Snapshot diterapkan.')
            : $this->reply(200, 'stale', 'Snapshot lebih lama dari yang tersimpan; diabaikan.');
    }

    private function signatureIsValid(Integration $integration, Request $request, string $rawBody): bool
    {
        $secret = (string) $integration->webhook_secret;
        $timestamp = trim((string) $request->header(self::TIMESTAMP_HEADER, ''));
        $signature = trim((string) $request->header(self::SIGNATURE_HEADER, ''));

        if ($secret === '' || ! preg_match('/\A[0-9]{1,12}\z/D', $timestamp) || $signature === '') {
            return false;
        }

        if (abs(time() - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

        return hash_equals($expected, strtolower($signature));
    }

    private function reply(int $status, string $code, string $message): JsonResponse
    {
        return response()->json(['status' => $code, 'message' => $message], $status);
    }
}
