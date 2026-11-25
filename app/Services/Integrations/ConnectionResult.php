<?php

namespace App\Services\Integrations;

use App\Models\Integration;
use App\Models\Unit;

final class ConnectionResult
{
    /**
     * @param  list<string>  $createdCategories
     * @param  list<string>  $mappedCategories
     */
    public function __construct(
        public readonly Integration $integration,
        public readonly Unit $unit,
        public readonly array $createdCategories,
        public readonly array $mappedCategories,
        public readonly ?string $webhookError,
    ) {}
}
