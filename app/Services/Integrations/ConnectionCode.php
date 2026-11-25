<?php

namespace App\Services\Integrations;

use App\Support\OutboundUrl;

/**
 * Kode koneksi dari sistem eksternal (kontrak §1.1): "hd1_" + base64url JSON
 * {v, name, url, token}. Satu kode berisi semua yang dibutuhkan helpdesk untuk
 * memanggil sistem itu, jadi admin cukup menempelkannya.
 */
final class ConnectionCode
{
    public const PREFIX = 'hd1_';

    private function __construct(
        public readonly string $name,
        public readonly string $baseUrl,
        public readonly string $token,
    ) {}

    public static function parse(?string $code): self
    {
        // Spasi/baris baru dari salin-tempel dibuang.
        $code = preg_replace('/\s+/', '', (string) $code) ?? '';

        if (! str_starts_with($code, self::PREFIX)) {
            throw new \InvalidArgumentException('Kode koneksi harus diawali "'.self::PREFIX.'". Salin ulang dari sistem tujuan.');
        }

        $json = base64_decode(strtr(substr($code, strlen(self::PREFIX)), '-_', '+/'), true);
        $payload = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($payload) || ($payload['v'] ?? null) !== 1) {
            throw new \InvalidArgumentException('Kode koneksi tidak terbaca. Pastikan kode disalin utuh.');
        }

        $url = is_string($payload['url'] ?? null) ? rtrim(trim($payload['url']), '/') : '';
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true) || filter_var($url, FILTER_VALIDATE_URL) === false || strlen($url) > 500) {
            throw new \InvalidArgumentException('Alamat di kode koneksi tidak valid.');
        }
        if (($problem = OutboundUrl::problem($url)) !== null) {
            throw new \InvalidArgumentException('Alamat di kode koneksi tidak valid: '.$problem);
        }

        $token = is_string($payload['token'] ?? null) ? trim($payload['token']) : '';
        if ($token === '' || strlen($token) > 500) {
            throw new \InvalidArgumentException('Kode koneksi tidak berisi token.');
        }

        $name = is_string($payload['name'] ?? null) ? trim($payload['name']) : '';

        return new self(mb_substr($name !== '' ? $name : (string) parse_url($url, PHP_URL_HOST), 0, 100), $url, $token);
    }

    public static function isValid(?string $code): bool
    {
        try {
            self::parse($code);

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
