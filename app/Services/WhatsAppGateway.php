<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class WhatsAppGateway
{
    public function __construct(
        private readonly ?Client $client = null,
    ) {}

    public function send($phoneNumber, string $message): bool
    {
        $target = $this->normalizeTarget($phoneNumber);

        if ($target === null) {
            Log::error('Gagal mengirim pesan WhatsApp: nomor tujuan kosong atau tidak valid.', [
                'receiver' => $phoneNumber,
            ]);

            return false;
        }

        $this->waitForSendTurn();

        $providers = $this->resolveProviders();
        $lastProvider = array_key_last($providers);

        foreach ($providers as $index => $provider) {
            $sent = match ($provider) {
                'waha' => $this->sendViaWaha($target, $message),
                'fonnte' => $this->sendViaFonnte($target, $message),
                default => false,
            };

            if ($sent) {
                return true;
            }

            if ($index !== $lastProvider) {
                Log::warning("WhatsApp gateway provider {$provider} gagal, mencoba provider berikutnya.", [
                    'receiver' => $target,
                ]);
            }
        }

        return false;
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
        $countryCode = preg_replace('/\D+/', '', (string) config('services.whatsapp_gateway.country_code', '62'));

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
        $minDigits = (int) config('services.whatsapp_gateway.min_digits', 10);
        $maxDigits = (int) config('services.whatsapp_gateway.max_digits', 15);

        if ($digitCount < $minDigits || $digitCount > $maxDigits) {
            return null;
        }

        return $target;
    }

    private function resolveProviders(): array
    {
        $primary = (string) config('services.whatsapp_gateway.provider', 'waha');
        $fallback = config('services.whatsapp_gateway.fallback_provider');
        $fallbackEnabled = (bool) config('services.whatsapp_gateway.fallback_enabled', true);

        $providers = [$primary];

        if (
            $fallbackEnabled
            && is_string($fallback)
            && trim($fallback) !== ''
            && $fallback !== $primary
        ) {
            $providers[] = $fallback;
        }

        return array_values(array_unique($providers));
    }

    private function sendViaWaha(string $target, string $message): bool
    {
        $baseUrl = config('services.whatsapp_gateway.waha.base_url');

        if (! is_string($baseUrl) || trim($baseUrl) === '') {
            Log::error('WHATSAPP_GATEWAY_WAHA_BASE_URL tidak diatur di file .env');

            return false;
        }

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        $apiKey = config('services.whatsapp_gateway.waha.api_key');

        if (is_string($apiKey) && trim($apiKey) !== '') {
            $headers['X-Api-Key'] = $apiKey;
        }

        try {
            $response = $this->client()->post(rtrim($baseUrl, '/').'/api/sendText', [
                'http_errors' => false,
                'headers' => $headers,
                'json' => [
                    'session' => (string) config('services.whatsapp_gateway.waha.session', 'default'),
                    'chatId' => $this->toWahaChatId($target),
                    'text' => $message,
                ],
            ]);

            $body = (string) $response->getBody();
            $statusCode = $response->getStatusCode();

            if ($statusCode >= 200 && $statusCode < 300) {
                return true;
            }

            Log::error('Gagal mengirim pesan WhatsApp via WAHA.', [
                'receiver' => $target,
                'status' => $statusCode,
                'body' => mb_substr($body, 0, 1000),
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error('Gagal mengirim pesan WhatsApp via WAHA: '.$e->getMessage(), [
                'receiver' => $target,
            ]);

            return false;
        }
    }

    private function sendViaFonnte(string $target, string $message): bool
    {
        $token = config('services.whatsapp_gateway.fonnte.token');

        if (! is_string($token) || trim($token) === '') {
            Log::error('WHATSAPP_GATEWAY_FONNTE_TOKEN tidak diatur di file .env');

            return false;
        }

        $endpoint = config('services.whatsapp_gateway.fonnte.endpoint');

        if (! is_string($endpoint) || trim($endpoint) === '') {
            Log::error('WHATSAPP_GATEWAY_FONNTE_ENDPOINT tidak diatur di file .env');

            return false;
        }

        try {
            $response = $this->client()->post($endpoint, [
                'http_errors' => false,
                'headers' => [
                    'Authorization' => $token,
                ],
                'multipart' => [
                    [
                        'name' => 'target',
                        'contents' => $target,
                    ],
                    [
                        'name' => 'message',
                        'contents' => $message,
                    ],
                    [
                        'name' => 'countryCode',
                        'contents' => (string) config('services.whatsapp_gateway.country_code', '62'),
                    ],
                ],
            ]);

            $body = (string) $response->getBody();

            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                Log::error('Gagal mengirim pesan WhatsApp via Fonnte.', [
                    'receiver' => $target,
                    'status' => $response->getStatusCode(),
                    'body' => mb_substr($body, 0, 1000),
                ]);

                return false;
            }

            $payload = json_decode($body, true);
            $status = is_array($payload) ? ($payload['status'] ?? $payload['Status'] ?? null) : null;

            if ($status === false || $status === 'false' || $status === 0 || $status === '0') {
                Log::error('Gagal mengirim pesan WhatsApp via Fonnte.', [
                    'receiver' => $target,
                    'reason' => is_array($payload) ? ($payload['reason'] ?? $payload['detail'] ?? $body) : $body,
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('Gagal mengirim pesan WhatsApp via Fonnte: '.$e->getMessage(), [
                'receiver' => $target,
            ]);

            return false;
        }
    }

    private function toWahaChatId(string $target): string
    {
        if (str_contains($target, '@')) {
            return $target;
        }

        return $target.'@c.us';
    }

    private function client(): Client
    {
        return $this->client ?? new Client([
            'timeout' => (float) config('services.whatsapp_gateway.timeout', 15),
        ]);
    }

    private function waitForSendTurn(): void
    {
        $minSecondsBetweenSends = max(0, (float) config('services.whatsapp_gateway.min_seconds_between_sends', 3));

        if ($minSecondsBetweenSends <= 0) {
            return;
        }

        $lockSeconds = (int) ceil($minSecondsBetweenSends + 10);

        try {
            Cache::lock('whatsapp-gateway-send-lock', $lockSeconds)
                ->block($lockSeconds, function () use ($minSecondsBetweenSends) {
                    $this->sleepUntilDelayPasses($minSecondsBetweenSends);
                });
        } catch (\Throwable $e) {
            Log::warning('WhatsApp gateway throttle lock gagal, memakai fallback delay lokal: '.$e->getMessage());

            $this->sleepUntilDelayPasses($minSecondsBetweenSends);
        }
    }

    private function sleepUntilDelayPasses(float $minSecondsBetweenSends): void
    {
        $lastSentAt = (float) Cache::get('whatsapp_gateway_last_sent_at', 0);
        $secondsSinceLastSend = microtime(true) - $lastSentAt;
        $secondsToWait = $minSecondsBetweenSends - $secondsSinceLastSend;

        if ($secondsToWait > 0) {
            usleep((int) ceil($secondsToWait * 1_000_000));
        }

        Cache::put('whatsapp_gateway_last_sent_at', microtime(true), now()->addMinutes(10));
    }
}
