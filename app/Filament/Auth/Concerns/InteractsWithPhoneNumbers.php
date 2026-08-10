<?php

namespace App\Filament\Auth\Concerns;

use App\Models\User;

trait InteractsWithPhoneNumbers
{
    protected function findActiveUserByPhone(string $phone): ?User
    {
        return $this->findUserByPhone($phone, true);
    }

    /**
     * Mencari user berdasarkan nomor HP dengan berbagai format kandidat.
     * Digunakan juga untuk mendeteksi akun nonaktif agar tidak bentrok
     * dengan constraint unik kolom phone saat auto-registrasi.
     */
    protected function findUserByPhone(string $phone, bool $activeOnly = true): ?User
    {
        $candidates = [$phone, '+'.$phone];

        if (str_starts_with($phone, '62')) {
            $candidates[] = '0'.substr($phone, 2);
            $candidates[] = substr($phone, 2);
        }

        $query = User::query()
            ->whereIn('phone', array_values(array_unique(array_filter($candidates))));

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        return $query->first();
    }

    protected function normalizePhone(mixed $value): ?string
    {
        $phone = trim((string) ($value ?? ''));

        if ($phone === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?: '';

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '0')) {
            $digits = '62'.substr($digits, 1);
        } elseif (str_starts_with($digits, '8')) {
            $digits = '62'.$digits;
        }

        return strlen($digits) >= 10 ? $digits : null;
    }

    protected function otpCacheKey(string $phone): string
    {
        return 'phone-otp-login:'.sha1($phone);
    }
}
