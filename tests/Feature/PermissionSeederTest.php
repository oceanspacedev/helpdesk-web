<?php

namespace Tests\Feature;

use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Tests\TestCase;

class PermissionSeederTest extends TestCase
{
    public function test_seeder_generates_shield_permissions_when_the_command_is_registered(): void
    {
        Artisan::shouldReceive('all')->once()->andReturn(['shield:generate' => true]);
        Artisan::shouldReceive('call')
            ->once()
            ->with('shield:generate', Mockery::on(fn (array $options): bool => $options['--ignore-existing-policies'] === true))
            ->andReturn(0);

        (new PermissionSeeder)->run();
    }

    public function test_seeder_skips_generation_when_shield_is_missing(): void
    {
        Artisan::shouldReceive('all')->once()->andReturn([]);
        Artisan::shouldReceive('call')->never();

        (new PermissionSeeder)->run();
    }
}
