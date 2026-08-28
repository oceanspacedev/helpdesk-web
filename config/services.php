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

    'helpdesk_mcp' => [
        'token' => env('HELPDESK_MCP_TOKEN'),
        'tokens' => array_values(array_filter(array_map(
            'trim',
            explode(',', implode(',', [
                (string) env('HELPDESK_MCP_TOKEN', ''),
                (string) env('HELPDESK_MCP_TOKENS', ''),
            ])),
        ))),
        'intake_ttl_minutes' => env('HELPDESK_MCP_INTAKE_TTL_MINUTES', 30),
        'rate_limit_per_minute' => env('HELPDESK_MCP_RATE_LIMIT_PER_MINUTE', 300),
        'identity_pepper' => env('HELPDESK_MCP_IDENTITY_PEPPER'),
        'identity_assertion_secret' => env('HELPDESK_MCP_IDENTITY_ASSERTION_SECRET'),
        'identity_assertion_leeway_seconds' => env('HELPDESK_MCP_IDENTITY_ASSERTION_LEEWAY_SECONDS', 300),
        'local_client_id' => env('HELPDESK_MCP_LOCAL_CLIENT_ID'),
    ],

    'whatsapp_otp' => [
        'ttl_minutes' => env('WHATSAPP_OTP_TTL_MINUTES', env('PHONE_OTP_LOGIN_TTL_MINUTES', 5)),
    ],

    'phone_otp_login' => [
        'ttl_minutes' => env('PHONE_OTP_LOGIN_TTL_MINUTES', 5),
    ],

    'talenta' => [
        // Path file JSON berisi data karyawan (struktur: { "data": { "data": [...] } })
        // Digunakan untuk auto-registrasi akun saat login OTP WhatsApp.
        'employee_file' => env('TALENTA_EMPLOYEE_FILE', base_path('talenta-list-employee.json')),
    ],
];
