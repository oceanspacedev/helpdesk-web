<?php

namespace App\Filament\Resources\TicketResource\Pages;

use App\Filament\Resources\TicketResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;

class ViewTicket extends ViewRecord
{
    protected static string $resource = TicketResource::class;

    protected function getHeaderActions(): array
    {
        $record = $this->record;
        $user = Auth::user();
        $actions = [
            Actions\EditAction::make(),
        ];

        // Check user roles
        if (($user->hasRole('Admin Unit')) || $user->hasRole('Super Admin')) {
            if ($record->ticket_statuses_id == 1 && ($record->responsible_id === null || $record->responsible_id == Auth::id())) {
                $actions[] = Actions\Action::make('cancel')
                    ->label('Cancel')
                    ->color('danger')
                    ->action(function () {
                        $record = $this->record;
                        $record->responsible_id = Auth::id();
                        $record->ticket_statuses_id = 3;
                        $record->save();
                        $this->notify('success', 'Ticket status updated to Cancelled');
                        $this->redirect($this->getResource()::getUrl('view', ['record' => $record->getKey()]));
                    });
                $actions[] = Actions\Action::make('proses')
                    ->label('Proses')
                    ->color('primary')
                    ->action(function () {
                        $record = $this->record;
                        $record->responsible_id = Auth::id();
                        $record->ticket_statuses_id = 2;
                        $record->save();
                        $this->notify('success', 'Ticket status updated to In Process');
                        $this->redirect($this->getResource()::getUrl('view', ['record' => $record->getKey()]));
                    });
                $actions[] = Actions\Action::make('selesai')
                    ->label('Selesai')
                    ->color('success')
                    ->action(function () {
                        $record = $this->record;
                        $record->responsible_id = Auth::id();
                        $record->ticket_statuses_id = 4;
                        $record->save();
                        $this->notify('success', 'Ticket status updated to Completed');
                        $this->redirect($this->getResource()::getUrl('view', ['record' => $record->getKey()]));
                    });
            } elseif ($record->ticket_statuses_id == 2 && $record->responsible_id == Auth::id()) {
                $actions[] = Actions\Action::make('cancel')
                    ->label('Cancel')
                    ->color('danger')
                    ->action(function () {
                        $record = $this->record;
                        $record->responsible_id = Auth::id();
                        $record->ticket_statuses_id = 3;
                        $record->save();
                        $this->notify('success', 'Ticket status updated to Cancelled');
                        $this->redirect($this->getResource()::getUrl('view', ['record' => $record->getKey()]));
                    });
                $actions[] = Actions\Action::make('selesai')
                    ->label('Selesai')
                    ->color('success')
                    ->action(function () {
                        $record = $this->record;
                        $record->responsible_id = Auth::id();
                        $record->ticket_statuses_id = 4;
                        $record->save();
                        $this->notify('success', 'Ticket status updated to Completed');
                        $this->redirect($this->getResource()::getUrl('view', ['record' => $record->getKey()]));
                    });
            }
        }

        return $actions;
    }
}
