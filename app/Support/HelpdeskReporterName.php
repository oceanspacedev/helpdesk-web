<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Str;

final class HelpdeskReporterName
{
    public const MAX_LENGTH = 100;

    private const REJECTED_RESPONSES = [
        'ya',
        'iya',
        'tidak',
        'lapor',
        'ok',
        'oke',
        'skip',
        'lewati',
        'batal',
    ];

    public static function normalize(string $value): ?string
    {
        $name = trim(preg_replace('/\s+/', ' ', strip_tags($value)) ?: '');

        if (Str::length($name) < 3
            || preg_match('/[\p{L}]/u', $name) !== 1
            || PhoneNumber::canonical($name) !== null
            || in_array(Str::lower($name), self::REJECTED_RESPONSES, true)) {
            return null;
        }

        return Str::limit($name, self::MAX_LENGTH, '');
    }

    /**
     * Nama sementara akun pelapor yang dibuat form publik dari nomor saja.
     */
    public static function placeholder(string $phone): string
    {
        return 'Pelapor WhatsApp '.substr($phone, -4);
    }

    /**
     * Akun belum punya nama sungguhan: bukan karyawan Talenta dan namanya
     * masih nama sementara dari nomor WhatsApp.
     */
    public static function isPlaceholder(User $user): bool
    {
        if (filled($user->identity)) {
            return false;
        }

        $phone = PhoneNumber::canonical($user->phone) ?? (string) $user->phone;

        return trim((string) $user->name) === '' || $user->name === self::placeholder($phone);
    }
}
