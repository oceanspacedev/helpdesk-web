<?php

namespace App\Support;

final class PhoneNumber
{
    public static function canonical(mixed $value): ?string
    {
        if (! is_scalar($value) && $value !== null) {
            return null;
        }

        $raw = strtolower(trim((string) $value));
        if ($raw === '' || strlen($raw) > 64 || str_contains($raw, '@lid')) {
            return null;
        }

        if (str_contains($raw, '@')) {
            if (! preg_match('/@(s\.whatsapp\.net|c\.us)$/', $raw)) {
                return null;
            }

            $raw = preg_replace('/@.+$/', '', $raw) ?: '';
        }

        if (preg_match('/[a-z]/', $raw)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $raw) ?: '';
        if ($digits === '' || preg_match('/^0+$/', $digits)) {
            return null;
        }

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '620')) {
            $digits = '62'.substr($digits, 3);
        } elseif (str_starts_with($digits, '0')) {
            $digits = '62'.substr($digits, 1);
        } elseif (str_starts_with($digits, '8')) {
            $digits = '62'.$digits;
        }

        return preg_match('/^[1-9][0-9]{9,14}$/D', $digits) ? $digits : null;
    }

    public static function reporterWriteLockKey(mixed $value): ?string
    {
        $phone = self::canonical($value);

        return $phone === null
            ? null
            : 'helpdesk:reporter-write:'.hash('sha256', $phone);
    }
}
