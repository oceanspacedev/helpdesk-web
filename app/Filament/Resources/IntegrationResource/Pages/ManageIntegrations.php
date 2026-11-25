<?php

namespace App\Filament\Resources\IntegrationResource\Pages;

use App\Filament\Resources\IntegrationResource;
use App\Filament\Resources\IntegrationResource\Actions\ConnectSystemAction;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Width;

class ManageIntegrations extends ManageRecords
{
    protected static string $resource = IntegrationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ConnectSystemAction::make(),
            Actions\CreateAction::make()
                ->label('Tambah manual')
                ->color('gray')
                ->slideOver()
                ->modalWidth(Width::Medium),
        ];
    }
}
