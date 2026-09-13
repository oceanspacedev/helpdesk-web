<?php

namespace App\Services;

use App\Support\PhpExecutionBudget;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class WhatsAppGateway
{
    /**
     * @param  mixed  $client  Optional Guzzle client (tests). Do not type-hint Client:
     *                         the container would inject a bare client with no timeout.
     */
    public function __construct(
        private mixed $client = null,
    ) {
        $this->client = $client instanceof Client ? $client : null;
    }

    public function send($phoneNumber, string $message): bool
    {
        $target = $this->normalizeTarget($phoneNumber);

        if ($target === null) {
            Log::error('Gagal mengirim pesan WhatsApp: nomor tujuan kosong atau tidak valid.', [
                'receiver' => $phoneNumber,
            ]);

            return false;
        }


        $url = config('services.whatsapp_gateway.url');
        $token = config('services.whatsapp_gateway.token');

        if (! is_string($url) || trim($url) === '') {
            Log::error('WAG_URL tidak diatur di file .env');

            return false;
        }

        $timeouts = $this->timeouts();
        if ($timeouts === null) {
            Log::warning('Pengiriman WhatsApp dilewati: sisa waktu eksekusi PHP tidak cukup.', [
                'receiver' => $target,
            ]);

            return false;
        }

        try {
            $idempotencyKey = 'helpdesk-' . (string) str()->uuid();
            $apiUrl = rtrim($url, '/') . '/api/v1/messages';

            $response = $this->client()->post($apiUrl, [
                'http_errors' => false,
                'timeout' => $timeouts['timeout'],
                'connect_timeout' => $timeouts['connect_timeout'],
                'headers' => [
                    'Authorization' => 'Bearer ' . ltrim($token ?? '', 'Bearer '),
                    'Accept' => 'application/json',
                    'Idempotency-Key' => $idempotencyKey,
                ],
                'json' => [
                    'recipient' => [
                        'type' => 'phone',
                        'value' => $target,
                    ],
                    'message' => [
                        'type' => 'text',
                        'text' => $message,
                    ],
                    'purpose' => 'notification',
                    'mode' => 'async',
                    'route_key' => 'default',
                    'client_reference' => 'helpdesk',
                ],
            ]);

            $body = (string) $response->getBody();

            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                Log::error('Gagal mengirim pesan WhatsApp via WAG.', [
                    'receiver' => $target,
                    'status' => $response->getStatusCode(),
                    'body' => mb_substr($body, 0, 1000),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('Gagal mengirim pesan WhatsApp via WAG: '.$e->getMessage(), [
                'receiver' => $target,
            ]);

            return false;
        }
    }

    public function normalizeTarget($phoneNumber): ?string
    {
        if ($phoneNumber === null) {
            return null;
        }

        $phoneNumber = trim((string) $phoneNumber);

        if ($phoneNumber === '') {
            return null;
        }

        if (str_ends_with($phoneNumber, '@g.us') || str_ends_with($phoneNumber, '@c.us')) {
            return $phoneNumber;
        }

        $target = preg_replace('/\D+/', '', $phoneNumber);
        $countryCode = '62';

        if ($target === null || $target === '') {
            return null;
        }

        if (str_starts_with($target, '00')) {
            $target = substr($target, 2);
        }

        if ($countryCode !== null && $countryCode !== '') {
            if (str_starts_with($target, '0')) {
                $target = $countryCode.ltrim($target, '0');
            } elseif (str_starts_with($target, '8')) {
                $target = $countryCode.$target;
            } elseif (str_starts_with($target, $countryCode.'0')) {
                $target = $countryCode.substr($target, strlen($countryCode) + 1);
            }
        }

        $digitCount = strlen($target);
        $minDigits = 10;
        $maxDigits = 15;

        if ($digitCount < $minDigits || $digitCount > $maxDigits) {
            return null;
        }

        return $target;
    }


    /**
     * @return array{timeout: float, connect_timeout: float}|null
     */
    public function timeouts(
        ?int $maxExecutionTime = null,
        ?float $requestStartedAt = null,
    ): ?array {
        $timeout = PhpExecutionBudget::capTimeout(
            (float) config('services.whatsapp_gateway.timeout', 8),
            2.0,
            1.0,
            $maxExecutionTime,
            $requestStartedAt,
        );
        if ($timeout === null) {
            return null;
        }

        $connect = min(
            $timeout,
            max(1.0, (float) config('services.whatsapp_gateway.connect_timeout', 5)),
        );

        return [
            'timeout' => $timeout,
            'connect_timeout' => $connect,
        ];
    }

    private function client(): Client
    {
        if ($this->client instanceof Client) {
            return $this->client;
        }

        $timeouts = $this->timeouts() ?? [
            'timeout' => 8.0,
            'connect_timeout' => 5.0,
        ];

        return new Client([
            'timeout' => $timeouts['timeout'],
            'connect_timeout' => $timeouts['connect_timeout'],
            'http_errors' => false,
        ]);
    }

}
