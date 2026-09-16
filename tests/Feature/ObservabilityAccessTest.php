<?php

namespace Tests\Feature;

use App\Support\ObservabilityAccess;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class ObservabilityAccessTest extends TestCase
{
    public function test_guests_cannot_access_horizon_or_log_viewer(): void
    {
        $this->assertFalse(ObservabilityAccess::allowed(null));
        $this->assertSame('horizon', config('horizon.path'));
        $this->assertSame('log-viewer', config('log-viewer.route_path'));
        $this->assertTrue(Gate::has('viewLogViewer'));
        $this->assertTrue(Gate::has('viewHorizon'));
    }
}
