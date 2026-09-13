<?php

namespace App\Support;

final class PhpExecutionBudget
{
    /**
     * Seconds left before PHP max_execution_time. Null means unlimited (CLI).
     */
    public static function remainingSeconds(
        ?int $maxExecutionTime = null,
        ?float $requestStartedAt = null,
    ): ?float {
        $max = $maxExecutionTime ?? (int) ini_get('max_execution_time');
        if ($max <= 0) {
            return null;
        }

        $started = $requestStartedAt
            ?? (isset($_SERVER['REQUEST_TIME_FLOAT'])
                ? (float) $_SERVER['REQUEST_TIME_FLOAT']
                : microtime(true));

        return $max - (microtime(true) - $started);
    }

    /**
     * Cap a desired I/O timeout so the request can still finish. Null means skip the I/O.
     */
    public static function capTimeout(
        float $desiredSeconds,
        float $reserveSeconds = 2.0,
        float $minimumSeconds = 1.0,
        ?int $maxExecutionTime = null,
        ?float $requestStartedAt = null,
    ): ?float {
        $desired = max($minimumSeconds, $desiredSeconds);
        $remaining = self::remainingSeconds($maxExecutionTime, $requestStartedAt);
        if ($remaining === null) {
            return $desired;
        }

        $budget = $remaining - $reserveSeconds;
        if ($budget < $minimumSeconds) {
            return null;
        }

        return min($desired, $budget);
    }
}
