<?php

namespace App\Services\Integrations\External;

use App\Models\Integration;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Klien HTTP untuk satu sistem eksternal sesuai kontrak v1
 * (docs/integrations/README.md). Token hanya dipakai di header Authorization
 * dan tidak pernah ditulis ke log.
 */
class ExternalSystemClient
{
    private const CONNECT_TIMEOUT_SECONDS = 5;

    public function __construct(
        private readonly Integration $integration,
    ) {}

    public static function for(Integration $integration): self
    {
        return new self($integration);
    }

    /**
     * GET {base}/categories.
     *
     * @return list<array{key: string, name: string, active: bool}>
     */
    public function categories(): array
    {
        $data = $this->data($this->send('get', 'categories'), 'categories');

        if (! array_is_list($data)) {
            throw $this->invalidResponse('categories');
        }

        $categories = [];
        foreach ($data as $category) {
            $key = is_array($category) && is_scalar($category['key'] ?? null) ? trim((string) $category['key']) : '';
            if ($key === '') {
                continue;
            }

            $categories[] = [
                'key' => $key,
                'name' => is_scalar($category['name'] ?? null) && trim((string) $category['name']) !== ''
                    ? trim((string) $category['name'])
                    : $key,
                'active' => (bool) ($category['active'] ?? true),
            ];
        }

        return $categories;
    }

    /**
     * GET {base}/forms/{category_key}: definisi form apa adanya
     * (key, title, description, downloads, fields).
     *
     * @return array<string, mixed>
     */
    public function form(string $categoryKey): array
    {
        $data = $this->data(
            $this->send('get', 'forms/'.rawurlencode($categoryKey)),
            'forms',
        );

        if (! is_array($data['fields'] ?? null)) {
            throw $this->invalidResponse('forms');
        }

        return $data;
    }

    /**
     * POST {base}/tickets (multipart). $payload berisi field non-file
     * (idempotency_key, helpdesk_ticket_number, category_key, title,
     * reporter[...], answers[...]); $files berbentuk sama dengan answers dan
     * berisi UploadedFile atau daftar UploadedFile (tipe "files").
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $files
     * @return array<string, mixed> TicketSnapshot ternormalisasi
     */
    public function submit(array $payload, array $files = []): array
    {
        $parts = [];
        foreach ($payload as $key => $value) {
            $this->appendParts($parts, (string) $key, $value);
        }
        $this->appendParts($parts, 'answers', $files);

        return $this->snapshot($this->send('post', 'tickets', $parts), 'tickets');
    }

    /**
     * GET {base}/tickets/{ref}.
     *
     * @return array<string, mixed> TicketSnapshot ternormalisasi
     */
    public function ticket(string $ref): array
    {
        return $this->snapshot(
            $this->send('get', 'tickets/'.rawurlencode($ref)),
            'tickets.show',
        );
    }

    /**
     * @param  list<array<string, mixed>>|null  $multipart
     */
    private function send(string $method, string $path, ?array $multipart = null): Response
    {
        if (! $this->integration->is_active) {
            throw new ExternalUnavailableException('Integrasi '.$this->integration->name.' sedang nonaktif.');
        }

        $baseUrl = rtrim(trim((string) $this->integration->base_url), '/');
        if ($baseUrl === '') {
            throw new ExternalUnavailableException('Base URL integrasi belum diatur.');
        }

        $url = $baseUrl.'/'.$path;

        try {
            $request = $this->request();
            $response = $multipart === null
                ? $request->send(strtoupper($method), $url)
                : $request->asMultipart()->post($url, $multipart);
        } catch (ConnectionException $exception) {
            $this->logFailure($path, null, 'connection');

            throw new ExternalUnavailableException('Sistem tujuan tidak dapat dihubungi.', 0, $exception);
        } catch (Throwable $exception) {
            $this->logFailure($path, null, class_basename($exception));

            throw new ExternalUnavailableException('Gagal menghubungi sistem tujuan.', 0, $exception);
        }

        $status = $response->status();
        if ($response->successful()) {
            return $response;
        }

        if ($status === 422) {
            $body = $response->json();
            $errors = [];
            foreach ((array) data_get($body, 'errors', []) as $field => $messages) {
                $errors[(string) $field] = array_values(array_map(
                    'strval',
                    array_filter((array) $messages, 'is_scalar'),
                ));
            }

            throw new ExternalValidationException(
                is_string(data_get($body, 'message')) ? (string) data_get($body, 'message') : '',
                $errors,
            );
        }

        if ($status === 404) {
            throw new ExternalNotFoundException('Data tidak ditemukan di sistem tujuan.');
        }

        $this->logFailure($path, $status, 'http_error');

        throw new ExternalUnavailableException(match (true) {
            $status === 401, $status === 403 => 'Sistem tujuan menolak kredensial helpdesk (HTTP '.$status.').',
            default => 'Sistem tujuan membalas HTTP '.$status.'.',
        });
    }

    private function request(): PendingRequest
    {
        $timeout = $this->integration->timeoutSeconds();

        return Http::withToken((string) $this->integration->token)
            ->acceptJson()
            ->timeout($timeout)
            ->connectTimeout(min(self::CONNECT_TIMEOUT_SECONDS, $timeout));
    }

    /**
     * @return array<mixed>
     */
    private function data(Response $response, string $endpoint): array
    {
        $data = data_get($response->json(), 'data');
        if (! is_array($data)) {
            throw $this->invalidResponse($endpoint);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Response $response, string $endpoint): array
    {
        try {
            return ExternalTicketSnapshot::normalize(data_get($response->json(), 'data'));
        } catch (\InvalidArgumentException $exception) {
            throw $this->invalidResponse($endpoint, $exception);
        }
    }

    private function invalidResponse(string $endpoint, ?Throwable $previous = null): ExternalUnavailableException
    {
        $this->logFailure($endpoint, null, 'invalid_response');

        return new ExternalUnavailableException('Respons sistem tujuan tidak sesuai kontrak.', 0, $previous);
    }

    /**
     * Ratakan nilai bersarang menjadi bagian multipart dengan nama ala form
     * HTML: answers[proses][0][judul]; daftar file menjadi answers[key][].
     *
     * @param  list<array<string, mixed>>  $parts
     */
    private function appendParts(array &$parts, string $name, mixed $value): void
    {
        if ($value === null) {
            return;
        }

        if ($value instanceof UploadedFile) {
            $parts[] = $this->filePart($name, $value);

            return;
        }

        if (is_array($value)) {
            $isFileList = $value !== [] && array_is_list($value)
                && array_reduce($value, fn (bool $carry, mixed $item): bool => $carry && $item instanceof UploadedFile, true);

            foreach ($value as $key => $item) {
                $this->appendParts($parts, $isFileList ? $name.'[]' : $name.'['.$key.']', $item);
            }

            return;
        }

        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        }

        if (! is_scalar($value)) {
            return;
        }

        $parts[] = ['name' => $name, 'contents' => (string) $value];
    }

    /**
     * @return array<string, mixed>
     */
    private function filePart(string $name, UploadedFile $file): array
    {
        // Stream, bukan isi file utuh, agar lampiran besar tidak dimuat
        // seluruhnya ke memori sebelum diteruskan.
        $path = $file->getRealPath();
        $contents = is_string($path) && $path !== '' ? @fopen($path, 'rb') : false;
        if ($contents === false) {
            throw new ExternalUnavailableException('Lampiran '.$file->getClientOriginalName().' tidak dapat dibaca.');
        }

        return [
            'name' => $name,
            'contents' => $contents,
            'filename' => $file->getClientOriginalName(),
            'headers' => ['Content-Type' => $file->getClientMimeType() ?: 'application/octet-stream'],
        ];
    }

    private function logFailure(string $endpoint, ?int $status, string $reason): void
    {
        Log::warning('Permintaan ke sistem eksternal gagal.', [
            'integration_id' => $this->integration->getKey(),
            'endpoint' => $endpoint,
            'status' => $status,
            'reason' => $reason,
        ]);
    }
}
