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
        ProblemCategory::create(['unit_id' => $unit->id, 'name' => 'Odoo Program']);
        ProblemCategory::create(['unit_id' => $unit->id, 'name' => 'Laptop, Komputer, Printer']);
        ProblemCategory::create(['unit_id' => $unit->id, 'name' => 'CSA Program']);
        ProblemCategory::create(['unit_id' => $unit->id, 'name' => 'CCTV']);
        ProblemCategory::create(['unit_id' => $unit->id, 'name' => 'Jaringan']);
        Priority::create(['name' => 'Medium']);
        BusinessEntity::create(['name' => 'CV. CS']);
        BusinessEntity::create(['name' => 'Complete Selular']);
    }
}
