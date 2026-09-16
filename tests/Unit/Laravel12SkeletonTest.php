<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class Laravel12SkeletonTest extends TestCase
{
    public function test_project_uses_laravel_12_entrypoints_and_env_names(): void
    {
        $root = dirname(__DIR__, 2);

        $artisan = (string) file_get_contents($root.'/artisan');
        $index = (string) file_get_contents($root.'/public/index.php');
        $phpunit = (string) file_get_contents($root.'/phpunit.xml');
        $composer = json_decode((string) file_get_contents($root.'/composer.json'), true);

        $this->assertTrue(str_starts_with((string) ($composer['require']['laravel/framework'] ?? ''), '^12.'));
        $this->assertStringContainsString('handleCommand', $artisan);
        $this->assertStringNotContainsString('Contracts\\Console\\Kernel', $artisan);
        $this->assertStringContainsString('handleRequest', $index);
        $this->assertStringNotContainsString('Contracts\\Http\\Kernel', $index);
        $this->assertFileDoesNotExist($root.'/app/Http/Kernel.php');
        $this->assertFileDoesNotExist($root.'/app/Console/Kernel.php');
        $this->assertFileDoesNotExist($root.'/app/Exceptions/Handler.php');
        $this->assertFileDoesNotExist($root.'/app/Providers/RouteServiceProvider.php');
        $this->assertFileDoesNotExist($root.'/app/Providers/EventServiceProvider.php');
        $this->assertFileDoesNotExist($root.'/app/Providers/BroadcastServiceProvider.php');
        $this->assertFileDoesNotExist($root.'/app/Providers/AuthServiceProvider.php');
        $this->assertTrue(str_starts_with((string) ($composer['require']['filament/filament'] ?? ''), '^4.'));
        $this->assertFileDoesNotExist($root.'/tests/CreatesApplication.php');
        $this->assertFileDoesNotExist($root.'/app/Http/Middleware/EncryptCookies.php');
        $this->assertFileDoesNotExist($root.'/app/Http/Middleware/VerifyCsrfToken.php');
        $this->assertStringContainsString('CACHE_STORE', $phpunit);
        $this->assertStringNotContainsString('CACHE_DRIVER', $phpunit);
        $this->assertStringNotContainsString('MAIL_ENCRYPTION', $phpunit);
        $this->assertStringNotContainsString('BROADCAST_DRIVER', $phpunit);
        $this->assertStringNotContainsString('BadgeColumn', (string) file_get_contents($root.'/app/Filament/Resources/TicketResource.php'));
        $this->assertStringNotContainsString('enableDownload()', (string) file_get_contents($root.'/app/Filament/Resources/TicketResource.php'));
    }
}
