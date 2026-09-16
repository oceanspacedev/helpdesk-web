<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class QueuedHelpdeskNotificationsTest extends TestCase
{
    public function test_ticket_notifications_queue_on_horizon_notifications(): void
    {
        $files = glob(dirname(__DIR__, 2).'/app/Notifications/*.php');

        $this->assertNotFalse($files);
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $this->assertStringContainsString(
                "\$this->onQueue('notifications')",
                (string) file_get_contents($file),
                basename($file).' must queue on notifications',
            );
        }
    }
}
