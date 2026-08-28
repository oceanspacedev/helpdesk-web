<?php

namespace Tests\Concerns;

use App\Models\BusinessEntity;
use App\Models\Priority;
use App\Models\ProblemCategory;
use App\Models\Unit;

trait SeedsHelpdeskMasterData
{
    protected function seedHelpdeskMasterData(): void
    {
        $unit = Unit::create(['name' => 'IT']);
        ProblemCategory::create(['unit_id' => $unit->id, 'name' => 'Akses Akun']);
        Priority::create(['name' => 'Medium']);
        BusinessEntity::create(['name' => 'Complete Selular']);
    }
}
