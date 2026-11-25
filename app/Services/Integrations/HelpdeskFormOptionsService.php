<?php

namespace App\Services\Integrations;

use App\Models\BusinessEntity;
use App\Models\Priority;
use App\Models\ProblemCategory;
use App\Models\Unit;

class HelpdeskFormOptionsService
{
    /**
     * @param  bool  $internalOnly  true untuk kanal chat (MCP/WhatsApp): unit
     *                              berintegrasi memakai form dinamis yang tidak didukung lewat chat,
     *                              sehingga unit dan kategorinya tidak ditawarkan sebagai pilihan.
     */
    public function formOptions(?int $unitId = null, bool $internalOnly = false): array
    {
        // is_external: unit berintegrasi memakai form dinamis dari sistem
        // eksternal (lihat ExternalTicketService::form()).
        $unitQuery = Unit::query()->acceptingTickets()->orderBy('name');
        if ($internalOnly) {
            $unitQuery->internal();
        }

        $units = $unitQuery->get(['id', 'name', 'integration_id'])->map(fn (Unit $unit) => [
            'id' => $unit->id,
            'name' => $unit->name,
            'is_external' => $unit->isExternal(),
        ])->values()->all();

        $externalUnitIds = Unit::query()->whereNotNull('integration_id')->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $categoryQuery = ProblemCategory::query()->orderBy('name');
        if ($unitId) {
            $categoryQuery->where('unit_id', $unitId);
        }
        if ($internalOnly && $externalUnitIds !== []) {
            $categoryQuery->whereNotIn('unit_id', $externalUnitIds);
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
                // Kategori unit berintegrasi: form diambil dari sistem
                // eksternal lewat endpoint public-tickets.external-form.
                'is_external' => in_array((int) $category->unit_id, $externalUnitIds, true),
            ])->values()->all(),
            'priorities' => $priorities,
            'business_entities' => $businessEntities,
        ];
    }
}
