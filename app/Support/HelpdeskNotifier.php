<?php

namespace App\Support;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

final class HelpdeskNotifier
{
    public static function send(
        object $notifiable,
        Notification $notification,
        ?int $maxExecutionTime = null,
        ?float $requestStartedAt = null,
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
    }
}
