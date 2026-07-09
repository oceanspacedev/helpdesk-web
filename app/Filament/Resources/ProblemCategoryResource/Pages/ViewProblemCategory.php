<?php

namespace App\Filament\Resources\ProblemCategoryResource\Pages;

use App\Filament\Resources\ProblemCategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewProblemCategory extends ViewRecord
{
    protected static string $resource = ProblemCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
