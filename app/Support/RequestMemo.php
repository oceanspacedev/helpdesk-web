<?php

namespace App\Support;

use Illuminate\Http\Request;
use WeakMap;

/**
 * Memo per request HTTP (termasuk setiap round-trip Livewire): hasil atau
 * exception satu panggilan disimpan untuk request itu saja, sehingga satu
 * render yang memanggil sumber yang sama berkali-kali (mis. closure
 * hidden()/visible() Filament) hanya sekali menghubungi sistem eksternal.
 * Request berikutnya selalu mencoba ulang. Entri juga kedaluwarsa setelah
 * TTL agar proses panjang tanpa request baru (queue/console) tidak memakai
 * hasil lama.
 */
final class RequestMemo
{
    private const TTL_SECONDS = 30;

    /** @var WeakMap<Request, array<string, array{at: float, value?: mixed, error?: \Throwable}>>|null */
    private static ?WeakMap $memo = null;

    /**
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    public static function remember(string $key, \Closure $callback): mixed
    {
        $request = app()->bound('request') ? app('request') : null;
        if (! $request instanceof Request) {
            return $callback();
        }

        self::$memo ??= new WeakMap;
        $entries = self::$memo[$request] ?? [];
        $entry = $entries[$key] ?? null;
        if ($entry !== null && microtime(true) - $entry['at'] <= self::TTL_SECONDS) {
            if (isset($entry['error'])) {
                throw $entry['error'];
            }

            return $entry['value'];
        }

        try {
            $value = $callback();
        } catch (\Throwable $exception) {
            $entries[$key] = ['at' => microtime(true), 'error' => $exception];
            self::$memo[$request] = $entries;

            throw $exception;
        }

        $entries[$key] = ['at' => microtime(true), 'value' => $value];
        self::$memo[$request] = $entries;

        return $value;
    }
}
