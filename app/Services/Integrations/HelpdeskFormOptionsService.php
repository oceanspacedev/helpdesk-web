<?php

namespace App\Services\Integrations;

use App\Models\BusinessEntity;
use App\Models\Priority;
use App\Models\ProblemCategory;
use App\Models\Unit;

class HelpdeskFormOptionsService
{
    public function formOptions(?int $unitId = null): array
    {
        $units = Unit::query()->orderBy('name')->get(['id', 'name'])->map(fn (Unit $unit) => [
            'id' => $unit->id,
            'name' => $unit->name,
        ])->values()->all();

        $categoryQuery = ProblemCategory::query()->orderBy('name');
        if ($unitId) {
            $categoryQuery->where('unit_id', $unitId);
        }

        $priorities = Priority::query()->orderBy('id')->get(['id', 'name'])->map(fn (Priority $priority) => [
            'id' => $priority->id,
            'name' => $priority->name,
        ])->values()->all();

        $businessEntities = BusinessEntity::query()->orderBy('name')->get(['id', 'name'])->map(fn (BusinessEntity $entity) => [
            'id' => $entity->id,
            'name' => $entity->name,
        ])->values()->all();

        return [
            'units' => $units,
            'problem_categories' => $categoryQuery->get(['id', 'unit_id', 'name'])->map(fn (ProblemCategory $category) => [
                'id' => $category->id,
                'unit_id' => $category->unit_id,
                'name' => $category->name,
            ])->values()->all(),
            'priorities' => $priorities,
            'business_entities' => $businessEntities,
        ];
    }
}
