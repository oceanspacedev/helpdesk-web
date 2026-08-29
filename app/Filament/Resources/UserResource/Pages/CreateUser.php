<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function afterCreate(): void
    {
        $this->syncLegacyUnitId();
    }

    private function syncLegacyUnitId(): void
    {
        $this->record->forceFill([
            'unit_id' => $this->record->units()->orderBy('units.id')->value('units.id'),
        ])->saveQuietly();
    }
}
