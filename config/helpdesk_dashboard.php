<?php

/*
|--------------------------------------------------------------------------
| Feed tiket read-only untuk dashboard luar (mis. layar TV tim IT)
|--------------------------------------------------------------------------
|
| Dashboard membaca daftar tiket divisi yang ditentukan di sini lewat
| /api/dashboard/tickets dan menerima tiket baru/berubah secara realtime
| lewat Server-Sent Events di /api/dashboard/tickets/stream.
|
*/

return [
    // Token akses, pisahkan dengan koma bila lebih dari satu dashboard.
    // Kosong berarti API dashboard mati (selalu 401).
    'tokens' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('HELPDESK_DASHBOARD_TOKENS', '')),
    ))),

    // Nama divisi tujuan tiket yang boleh dibaca dashboard.
    'unit_names' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('HELPDESK_DASHBOARD_UNITS', 'IT')),
    ))),

    // Seberapa sering stream mengecek database (detik).
    'stream_poll_seconds' => (int) env('HELPDESK_DASHBOARD_STREAM_POLL', 3),

    // Lama satu koneksi stream sebelum ditutup; EventSource di browser
    // otomatis menyambung ulang dan melanjutkan dari Last-Event-ID.
    'stream_lifetime_seconds' => (int) env('HELPDESK_DASHBOARD_STREAM_LIFETIME', 300),
];
