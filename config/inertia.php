<?php

return [

    'ssr' => [
        'enabled' => (bool) env('INERTIA_SSR_ENABLED', false),
        'runtime' => env('INERTIA_SSR_RUNTIME', 'node'),
        'ensure_runtime_exists' => (bool) env('INERTIA_SSR_ENSURE_RUNTIME_EXISTS', false),
        'url' => env('INERTIA_SSR_URL', 'http://127.0.0.1:13714'),
        'hot_url' => env('INERTIA_SSR_HOT_URL'),
        'ensure_bundle_exists' => (bool) env('INERTIA_SSR_ENSURE_BUNDLE_EXISTS', true),
        'throw_on_error' => (bool) env('INERTIA_SSR_THROW_ON_ERROR', false),
    ],

    'pages' => [
        'ensure_pages_exist' => false,
        'paths' => [
            resource_path('js/pages'),
        ],
        'extensions' => [
            'js',
            'vue',
        ],
    ],

    'testing' => [
        'ensure_pages_exist' => true,
    ],

    'expose_shared_prop_keys' => true,

    'history' => [
        'encrypt' => (bool) env('INERTIA_ENCRYPT_HISTORY', false),
    ],

    'devtools' => [
        'enabled' => env('INERTIA_DEVTOOLS_ENABLED'),
        'except' => ['telescope*', 'horizon*', '_inertia/devtools*'],
        'storage' => [
            'path' => storage_path('inertia-devtools'),
            'ttl' => (int) env('INERTIA_DEVTOOLS_TTL_HOURS', 24),
            'prune_interval' => (int) env('INERTIA_DEVTOOLS_PRUNE_INTERVAL_SECONDS', 300),
            'limit' => (int) env('INERTIA_DEVTOOLS_LIMIT', 100),
        ],
        'middleware' => ['web'],
        'gate' => env('INERTIA_DEVTOOLS_GATE'),
        'redact' => [
            'keys' => [
                'password',
                'password_confirmation',
                'current_password',
                'token',
                '_token',
                'access_token',
                'refresh_token',
                'secret',
                'client_secret',
                'api_key',
            ],
            'headers' => [
                'cookie',
                'set-cookie',
                'authorization',
                'proxy-authorization',
                'x-xsrf-token',
                'x-csrf-token',
            ],
        ],
    ],

];
