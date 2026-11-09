<?php

namespace Tests\Feature;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Tests\TestCase;

class LockedPropertyTamperingTest extends TestCase
{
    public function test_tampered_locked_property_is_rejected_without_being_reported(): void
    {
        config()->set('app.debug', false);
        $handler = $this->app->make(ExceptionHandler::class);
        $exception = new CannotUpdateLockedPropertyException('userUndertakingMultiFactorAuthentication');

        $this->assertFalse($handler->shouldReport($exception));
        $this->assertSame(419, $handler->render(Request::create('/livewire/update', 'POST'), $exception)->getStatusCode());
    }
}
