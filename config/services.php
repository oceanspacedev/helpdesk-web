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
        'url' => env('WAG_URL', 'https://waghub.mekayastudio.com'),
        'token' => env('WAG_TOKEN'),
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
