<?php

namespace App\Support;

use Closure;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

final class HelpdeskNotifier
{
    /**
     * Kirim satu notifikasi tanpa mengganggu notifikasi lain.
     *
     * Notifikasi Helpdesk menunggu commit (ShouldQueueAfterCommit). Pada queue
     * sync, job after-commit dijalankan berurutan oleh transaksi, dan satu
     * exception (mis. SMTP timeout) menghentikan job berikutnya tanpa jejak di
     * failed_jobs. Pengiriman karena itu didaftarkan sendiri sebagai callback
     * after-commit yang masing-masing punya penjaga exception, sehingga email
     * PIC yang gagal tidak menggugurkan WhatsApp PIC maupun tanda terima pelapor.
     */
    public static function send(
        object $notifiable,
        Notification $notification,
        ?int $maxExecutionTime = null,
        ?float $requestStartedAt = null,
    ): void {
        self::afterCommit(static function () use (
            $notifiable,
            $notification,
            $maxExecutionTime,
            $requestStartedAt,
        ): void {
            $remaining = PhpExecutionBudget::remainingSeconds($maxExecutionTime, $requestStartedAt);
            if ($remaining !== null) {
                $mailTimeout = max(1, (int) config('mail.mailers.smtp.timeout', 10));
                $waTimeout = max(1.0, (float) config('services.whatsapp_gateway.timeout', 8));
                $needed = max($mailTimeout, $waTimeout) + 2.0;
                if ($remaining < $needed) {
                    Log::warning('Notifikasi dilewati: sisa waktu eksekusi PHP tidak cukup.', [
                        'notification' => $notification::class,
                        'remaining_seconds' => $remaining,
                    ]);

                    return;
                }
            }

            try {
                $notifiable->notify($notification);
            } catch (Throwable $exception) {
                Log::error('Gagal mengirim notifikasi Helpdesk: '.$exception->getMessage(), [
                    'notification' => $notification::class,
                    'notifiable_type' => $notifiable::class,
                    'notifiable_id' => $notifiable->getKey() ?? null,
                ]);
            }
        });
    }

    /**
     * Jalankan setelah transaksi aktif commit; langsung bila tidak ada transaksi.
     * Memakai manajer transaksi yang sama dengan queue, sehingga saat callback
     * ini berjalan tidak ada transaksi tertunda dan notify() langsung dieksekusi
     * (sync) atau didorong ke antrean (redis).
     */
    private static function afterCommit(Closure $callback): void
    {
        if (! app()->bound('db.transactions')) {
            $callback();

            return;
        }

        app('db.transactions')->addCallback($callback);
    }
}
