<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_CLIENT_REDIRECT'),
    ],

    'whatsapp_gateway' => [
        'provider' => env('WHATSAPP_GATEWAY_PROVIDER', 'waha'),
        'fallback_provider' => env('WHATSAPP_GATEWAY_FALLBACK_PROVIDER', 'fonnte'),
        'fallback_enabled' => env('WHATSAPP_GATEWAY_FALLBACK_ENABLED', true),
        'default_target' => env('WHATSAPP_GATEWAY_DEFAULT_TARGET', env('WHATSAPP_DEFAULT_TARGET', env('FONNTE_DEFAULT_TARGET', env('DEFAULT_NOTIFICATION_PHONE')))),
        'waha' => [
            'base_url' => env('WHATSAPP_GATEWAY_WAHA_BASE_URL', env('WAHA_API_ENDPOINT', env('WAHA_BASE_URL', 'http://localhost:3000'))),
            'api_key' => env('WHATSAPP_GATEWAY_WAHA_API_KEY', env('WAHA_API_KEY')),
            'session' => env('WHATSAPP_GATEWAY_WAHA_SESSION', env('WAHA_SESSION', 'default')),
        ],
        'fonnte' => [
            'endpoint' => env('WHATSAPP_GATEWAY_FONNTE_ENDPOINT', env('FONNTE_API_ENDPOINT', env('FONNTE_ENDPOINT', env('WHATSAPP_GATEWAY_ENDPOINT', 'https://api.fonnte.com/send')))),
            'token' => env('WHATSAPP_GATEWAY_FONNTE_TOKEN', env('FONNTE_TOKEN', env('WHATSAPP_GATEWAY_TOKEN'))),
        ],
        'country_code' => env('WHATSAPP_GATEWAY_COUNTRY_CODE', env('WHATSAPP_COUNTRY_CODE', env('FONNTE_COUNTRY_CODE', '62'))),
        'timeout' => env('WHATSAPP_GATEWAY_TIMEOUT', 15),
        'min_seconds_between_sends' => env('WHATSAPP_GATEWAY_MIN_SECONDS_BETWEEN_SENDS', 3),
        'min_digits' => env('WHATSAPP_GATEWAY_MIN_DIGITS', 10),
        'max_digits' => env('WHATSAPP_GATEWAY_MAX_DIGITS', 15),
    ],

    'ita_helpdesk' => [
        'token' => env('ITA_HELPDESK_API_TOKEN'),
        'idempotency_ttl_minutes' => env('ITA_HELPDESK_IDEMPOTENCY_TTL_MINUTES', 15),
        'default_unit_name' => env('ITA_HELPDESK_DEFAULT_UNIT_NAME', 'IT'),
        'fallback_problem_category_name' => env('ITA_HELPDESK_FALLBACK_PROBLEM_CATEGORY_NAME', 'Laporan ITA'),
    ],

    'phone_otp_login' => [
        'ttl_minutes' => env('PHONE_OTP_LOGIN_TTL_MINUTES', 5),
    ],
];
