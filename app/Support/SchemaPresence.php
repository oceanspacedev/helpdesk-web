<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Cek keberadaan tabel yang murah untuk jalur request.
 *
 * Schema::hasTable() selalu bertanya ke information_schema; dipanggil dari
 * policy per baris tabel, satu halaman bisa mengirim puluhan query itu.
 * Hasil "ada" diingat di container (per request, per test), hasil "tidak
 * ada" sengaja tidak diingat supaya tabel yang dibuat belakangan dalam
 * proses yang sama (skema test bertahap) tetap terdeteksi.
 */
final class SchemaPresence
{
    public static function has(string $table): bool
    {
        $key = 'helpdesk.schema-presence.'.$table;

        if (app()->bound($key)) {
            return true;
        }

        if (! Schema::hasTable($table)) {
            return false;
        }

        app()->instance($key, true);

        return true;
    }
}
