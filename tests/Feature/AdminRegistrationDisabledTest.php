<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminRegistrationDisabledTest extends TestCase
{
    public function test_breezy_registration_is_disabled_by_configuration(): void
    {
        $this->assertFalse(config('filament-breezy.enable_registration'));
    }

    public function test_admin_register_route_is_not_available(): void
    {
        $this->assertFalse(Route::has('register'));

        $this->get('/admin/register')
            ->assertNotFound();
    }
}
