<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PermissionSeederSafetyTest extends TestCase
{
    public function test_permission_generation_preserves_custom_policies(): void
    {
        $source = file_get_contents(__DIR__.'/../../database/seeders/PermissionSeeder.php');

        $this->assertIsString($source);
        $this->assertStringContainsString("'--ignore-existing-policies' => true", $source);
    }
}
