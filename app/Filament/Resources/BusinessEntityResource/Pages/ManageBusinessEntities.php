<?php

namespace App\Filament\Resources\BusinessEntityResource\Pages;

use App\Filament\Resources\BusinessEntityResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageBusinessEntities extends ManageRecords
{
    protected static string $resource = BusinessEntityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
