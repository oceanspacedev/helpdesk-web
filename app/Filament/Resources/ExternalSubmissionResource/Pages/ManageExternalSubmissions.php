<?php

namespace App\Filament\Resources\ExternalSubmissionResource\Pages;

use App\Filament\Resources\ExternalSubmissionResource;
use Filament\Resources\Pages\ManageRecords;

class ManageExternalSubmissions extends ManageRecords
{
    protected static string $resource = ExternalSubmissionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
