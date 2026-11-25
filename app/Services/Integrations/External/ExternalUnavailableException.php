<?php

namespace App\Services\Integrations\External;

/**
 * Sistem eksternal tidak bisa dipakai saat ini: 5xx, timeout, gagal koneksi,
 * ditolak (401/403), integrasi nonaktif, atau respons tidak sesuai kontrak.
 */
class ExternalUnavailableException extends ExternalSystemException
{
    public const USER_MESSAGE = 'Sistem tujuan sedang tidak tersedia. Isian Anda tidak hilang, silakan coba kirim lagi beberapa saat lagi.';

    public function userMessage(): string
    {
        return self::USER_MESSAGE;
    }
}
