<?php

namespace App\Filament\Resources\UnitSlas\Pages;

use App\Filament\Resources\UnitSlas\UnitSlaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Width;

class ManageUnitSlas extends ManageRecords
{
    protected static string $resource = UnitSlaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->slideOver()
                ->modalWidth(Width::Medium),
        ];
    }
}
