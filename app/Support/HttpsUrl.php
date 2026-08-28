<?php

namespace App\Support;

use Illuminate\Support\Str;

final class HttpsUrl
{
    public const MAX_LENGTH = 2048;

    public static function isValid(string $url): bool
    {
        return $url !== ''
            && Str::length($url) <= self::MAX_LENGTH
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
    }
}
