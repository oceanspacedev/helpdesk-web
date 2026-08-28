<?php

namespace App\Notifications\Concerns;

trait ResolvesHelpdeskNotificationChannels
{
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
