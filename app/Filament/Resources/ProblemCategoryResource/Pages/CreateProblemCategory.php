<?php

namespace App\Filament\Resources\ProblemCategoryResource\Pages;

use App\Filament\Resources\ProblemCategoryResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateProblemCategory extends CreateRecord
{
    protected static string $resource = ProblemCategoryResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = Auth::user();

        abort_unless(
            $user instanceof User && $user->canAdministerUnit((int) ($data['unit_id'] ?? 0)),
            403,
        );

        return $data;
    }
}
