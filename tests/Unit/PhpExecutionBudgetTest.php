<?php

namespace Tests\Unit;

use App\Support\PhpExecutionBudget;
use Tests\TestCase;

class PhpExecutionBudgetTest extends TestCase
{
    public function test_unlimited_when_max_execution_time_is_zero(): void
    {
        $this->assertNull(PhpExecutionBudget::remainingSeconds(0, microtime(true)));
        $this->assertSame(8.0, PhpExecutionBudget::capTimeout(8.0, 2.0, 1.0, 0, microtime(true)));
    }

    public function test_caps_timeout_to_remaining_budget(): void
    {
        $started = microtime(true) - 20;
        $capped = PhpExecutionBudget::capTimeout(8.0, 2.0, 1.0, 30, $started);

        $this->assertNotNull($capped);
        $this->assertLessThanOrEqual(8.0, $capped);
        $this->assertGreaterThanOrEqual(1.0, $capped);
        $this->assertLessThan(10.0, $capped);
    }

    public function test_skips_io_when_php_time_is_almost_exhausted(): void
    {
        $started = microtime(true) - 29;
        $this->assertNull(PhpExecutionBudget::capTimeout(8.0, 2.0, 1.0, 30, $started));
    }
}
