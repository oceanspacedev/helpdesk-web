<?php

namespace App\Notifications\Concerns;

trait ResolvesHelpdeskNotificationChannels
{
    public int $tries = 3;

    public int $timeout = 60;

    /**
     * @param  list<class-string|string>  $channels
     * @return list<class-string|string>
     */
    protected function withVerifiedMailChannel(object $notifiable, array $channels): array
    {
        if (method_exists($notifiable, 'canUseVerifiedEmail') && $notifiable->canUseVerifiedEmail()) {
            array_unshift($channels, 'mail');
        }

        return $channels;
    }
}
