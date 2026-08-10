<?php

namespace App\Channels;

use App\Services\WhatsAppGateway;
use Illuminate\Notifications\Notification;

class WhatsAppChannel
{
    /**
     * Send the given notification.
     */
    public function send(object $notifiable, Notification $notification): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        if (method_exists($notification, 'toWhatsapp')) {
            $message = $notification->toWhatsapp($notifiable);
            
            // If the method already handles the sending (returns null/void) we ignore, 
            // but ideally we should update the notifications to RETURN the message string.
            if (is_string($message) && !empty($message)) {
                $phoneNumber = $notifiable->routeNotificationFor('whatsapp') 
                    ?? $notifiable->phone;

                if ($phoneNumber) {
                    app(WhatsAppGateway::class)->send($phoneNumber, $message);
                }
            }
        }
    }

    /**
     * Gateway belum dikonfigurasi -> jangan coba kirim agar tidak
     * membanjiri log dengan error pada setiap event notifikasi.
     */
    protected function isConfigured(): bool
    {
        return !empty(config('services.whatsapp_gateway.url'))
            && !empty(config('services.whatsapp_gateway.token'));
    }
}
