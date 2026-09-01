<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesHelpdeskIntegrationSchema;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use CreatesHelpdeskIntegrationSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTestSchema();
    }

    /**
     * A basic test example.
     */
    public function test_the_application_shows_the_public_ticket_form(): void
    {
        $response = $this->get('/');

        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('PublicTickets/Create')
                ->where('screen', 'identify')
                ->where('reporter', null)
            )
            ->assertDontSee('OTP')
            ->assertDontSee('Nama lengkap');
    }
}
