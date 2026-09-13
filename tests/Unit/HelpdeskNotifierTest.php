<?php

namespace Tests\Unit;

use App\Support\HelpdeskNotifier;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class HelpdeskNotifierTest extends TestCase
{
    public function test_it_swallows_delivery_failures_so_the_request_can_finish(): void
    {
        Log::spy();

        $notifiable = new class
        {
            public function notify(Notification $notification): void
            {
                throw new RuntimeException('Maximum execution time of 30 seconds exceeded');
            }

            public function getKey(): int
            {
                return 45;
            }
        };

        $notification = new class extends Notification
        {
            public function via(object $notifiable): array
            {
                return [];
            }
        };

        HelpdeskNotifier::send($notifiable, $notification);

        Log::shouldHaveReceived('error')->once();
    }

    public function test_it_skips_send_when_php_execution_time_is_almost_exhausted(): void
    {
        Log::spy();

        $state = (object) ['calls' => 0];
        $notifiable = new class($state)
        {
            public function __construct(private object $state) {}

            public function notify(Notification $notification): void
            {
                $this->state->calls++;
            }

            public function getKey(): int
            {
                return 1;
            }
        };

        $notification = new class extends Notification
        {
            public function via(object $notifiable): array
            {
                return [];
            }
        };

        HelpdeskNotifier::send($notifiable, $notification, 30, microtime(true) - 25);

        $this->assertSame(0, $state->calls);
        Log::shouldHaveReceived('warning')->once();
    }
}
