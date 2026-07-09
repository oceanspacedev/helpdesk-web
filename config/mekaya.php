<?php

return [

    'admin' => [
        // Panel path + asset URL prefix. Should match the panel's ->path().
        'path' => 'admin',

        // Version label surfaced in the appshell.
        'version' => 'v2',

        // Optional brand image path (relative to /public). Null falls back to the bundled mekaya icon.
        'brand' => null,

        // Brand logo height in the panel header.
        'brand_logo_height' => '2rem',

        // Favicon path (relative to /public).
        'favicon' => 'admin/images/favicons/favicon.ico',
    ],

    'settings' => [
        'name' => env('APP_NAME', 'Mekaya'),
        'email' => env('MAIL_FROM_ADDRESS', 'admin@admin.com'),
    ],

];
