<?php

namespace App\Support;

/**
 * Pemeriksaan URL yang akan dipanggil server ini atas nama sistem lain
 * (integrasi helpdesk ↔ sistem eksternal). Alamat jaringan internal biasa
 * tetap boleh, karena helpdesk dan sistem eksternal sering satu jaringan,
 * tetapi alamat yang tidak pernah sah untuk integrasi ditolak: kredensial di
 * URL dan alamat link-local / metadata cloud (169.254.0.0/16, fe80::/10).
 */
final class OutboundUrl
{
    private const BLOCKED_HOSTS = ['metadata', 'metadata.google.internal', 'metadata.azure.com', '0.0.0.0', '::'];

    /** Null bila aman; selain itu alasan penolakan. */
    public static function problem(string $url): ?string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return 'Alamat harus berupa URL http(s) yang valid.';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'Alamat tidak boleh berisi nama pengguna atau kata sandi.';
        }

        if (in_array($host, self::BLOCKED_HOSTS, true)) {
            return 'Alamat tersebut tidak diizinkan.';
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        foreach ($ips as $ip) {
            if (self::isLinkLocal($ip)) {
                return 'Alamat link-local / metadata cloud tidak diizinkan.';
            }
        }

        return null;
    }

    private static function isLinkLocal(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return str_starts_with($ip, '169.254.') || $ip === '0.0.0.0';
        }

        $packed = @inet_pton($ip);

        return $packed !== false && strlen($packed) === 16
            && (ord($packed[0]) === 0xFE && (ord($packed[1]) & 0xC0) === 0x80);
    }
}
