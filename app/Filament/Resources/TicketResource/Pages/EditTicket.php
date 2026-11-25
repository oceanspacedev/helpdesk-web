<?php

namespace App\Filament\Resources\TicketResource\Pages;

use App\Filament\Resources\TicketResource;
use App\Support\SafeUploadedFile;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTicket extends EditRecord
{
    protected static string $resource = TicketResource::class;

    protected function authorizeAccess(): void
    {
        // Tiket eksternal (tiket tipis) tidak bisa diubah atau dipindah dari
        // helpdesk, termasuk oleh Super Admin.
        abort_if($this->getRecord()->isExternal(), 403);

        parent::authorizeAccess();
    }

    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        SafeUploadedFile::wrapValidation(
            fn () => parent::save($shouldRedirect, $shouldSendSavedNotification),
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
            Actions\ForceDeleteAction::make(),
            Actions\RestoreAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        abort_if($this->getRecord()->isExternal(), 403);

        if (! auth()->user()?->hasGlobalTicketAccess()) {
            $data['unit_id'] = $this->getRecord()->unit_id;
        }

        return $data;
    }
}
