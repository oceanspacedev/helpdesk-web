<?php

namespace App\Filament\Resources\ProblemCategoryResource\Pages;

use App\Filament\Resources\ProblemCategoryResource;
use App\Models\User;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditProblemCategory extends EditRecord
{
    protected static string $resource = ProblemCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
            Actions\ForceDeleteAction::make(),
            Actions\RestoreAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $user = Auth::user();

        abort_unless(
            $user instanceof User && $user->canAdministerUnit((int) ($data['unit_id'] ?? 0)),
            403,
        );

        return $data;
    }
}
