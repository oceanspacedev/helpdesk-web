<?php

namespace App\Notifications\Concerns;

trait ResolvesHelpdeskNotificationChannels
{
    public int $tries = 3;

    public int $timeout = 60;

    /**
     * Email selalu di urutan terakhir. Pada queue sync setiap channel dikirim
     * berurutan di dalam request yang sama, dan SMTP yang lambat atau gagal
     * tidak boleh mendahului WhatsApp dan notifikasi lonceng.
     *
     * @param  list<class-string|string>  $channels
     * @return list<class-string|string>
     */
    protected function withVerifiedMailChannel(object $notifiable, array $channels): array
    {
        if (method_exists($notifiable, 'canUseVerifiedEmail') && $notifiable->canUseVerifiedEmail()) {
            $channels[] = 'mail';
        }

        return $channels;
    }
}
