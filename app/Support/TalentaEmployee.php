<?php

namespace App\Support;

use Illuminate\Support\Str;

final class TalentaEmployee
{
    public static function name(array $employee): ?string
    {
        $name = trim(strip_tags(
            (string) ($employee['first_name'] ?? '').' '.(string) ($employee['last_name'] ?? ''),
        ));

        return $name !== '' ? $name : null;
    }

    public static function email(array $employee): string
    {
        return strtolower(trim((string) ($employee['email'] ?? '')));
    }

    public static function identity(array $employee): ?string
    {
        $identity = trim((string) (
            $employee['id_employee']
                ?? $employee['user_id']
                ?? $employee['id']
                ?? $employee['nik']
                ?? ''
        ));

        return $identity !== '' ? $identity : null;
    }

    /**
     * @return list<string>
     */
    public static function normalizedPhones(array $employee): array
    {
        $phones = array_values(array_unique(array_filter([
            PhoneNumber::canonical($employee['mobile_phone'] ?? null),
            PhoneNumber::canonical($employee['phone'] ?? null),
        ])));
        sort($phones);

        return $phones;
    }

    public static function identityFingerprint(array $employee): string
    {
        return hash('sha256', implode("\0", [
            (string) (
                $employee['id_employee']
                    ?? $employee['user_id']
                    ?? $employee['id']
                    ?? $employee['nik']
                    ?? ''
            ),
            Str::lower(trim((string) ($employee['email'] ?? ''))),
            trim((string) ($employee['first_name'] ?? '')),
            trim((string) ($employee['last_name'] ?? '')),
            implode(',', self::normalizedPhones($employee)),
        ]));
    }
}
