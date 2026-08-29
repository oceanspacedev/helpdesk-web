<?php

namespace App\Filament\Resources\TicketResource\Pages;

use App\Filament\Resources\TicketResource;
use App\Models\Ticket;
use App\Models\TicketStatus;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ViewTicket extends ViewRecord
{
    protected static string $resource = TicketResource::class;

    protected function getHeaderActions(): array
    {
        $record = $this->record;
        $user = Auth::user();

        if (! $user) {
            return [];
        }

        $actions = [];

        if ($user->can('update', $record)) {
            $actions[] = Actions\EditAction::make()
                ->authorize('update');
        }

        if (! $user->can('process', $record)) {
            return $actions;
        }

        $isGlobalProcessor = $user->hasGlobalTicketAccess();
        $responsible = $record->eligibleResponsible();
        $isAvailableToUser = $responsible === null
            || (int) $responsible->getKey() === (int) $user->getKey()
            || $isGlobalProcessor;

        if ((int) $record->ticket_statuses_id === TicketStatus::OPEN && $isAvailableToUser) {
            $actions[] = $this->transitionAction(
                'proses',
                'Proses',
                'primary',
                TicketStatus::IN_PROGRESS,
                'Tiket berhasil diambil dan sedang diproses.',
            );
            $actions[] = $this->transitionAction(
                'cancel',
                'Batalkan',
                'danger',
                TicketStatus::CANCEL,
                'Tiket berhasil dibatalkan.',
            );
        } elseif ((int) $record->ticket_statuses_id === TicketStatus::IN_PROGRESS
            && $responsible === null
            && ! $isGlobalProcessor) {
            $actions[] = $this->transitionAction(
                'ambil_alih',
                'Ambil Alih',
                'primary',
                TicketStatus::IN_PROGRESS,
                'Tiket berhasil diambil alih dan dapat dilanjutkan.',
            );
        } elseif ((int) $record->ticket_statuses_id === TicketStatus::IN_PROGRESS
            && ((int) $responsible?->getKey() === (int) $user->getKey() || $isGlobalProcessor)) {
            $actions[] = $this->transitionAction(
                'selesai',
                'Selesai',
                'success',
                TicketStatus::CLOSED,
                'Tiket berhasil diselesaikan.',
            );
            $actions[] = $this->transitionAction(
                'cancel',
                'Batalkan',
                'danger',
                TicketStatus::CANCEL,
                'Tiket berhasil dibatalkan.',
            );
        }

        return $actions;
    }

    private function transitionAction(
        string $name,
        string $label,
        string $color,
        int $targetStatus,
        string $successMessage,
    ): Actions\Action {
        return Actions\Action::make($name)
            ->label($label)
            ->color($color)
            ->authorize('process')
            ->action(fn () => $this->transitionTicket($targetStatus, $successMessage));
    }

    private function transitionTicket(int $targetStatus, string $successMessage): void
    {
        $updated = DB::transaction(function () use ($targetStatus): bool {
            /** @var Ticket $record */
            $record = Ticket::query()
                ->withTrashed()
                ->lockForUpdate()
                ->findOrFail($this->record->getKey());

            if ($record->trashed()) {
                return false;
            }

            $user = Auth::user();
            $userId = (int) $user->getKey();
            $isGlobalProcessor = $user->hasGlobalTicketAccess();
            $responsible = $record->eligibleResponsible();
            $isAvailableToUser = $responsible === null
                || (int) $responsible->getKey() === $userId
                || $isGlobalProcessor;

            $isAllowedTransition = match ($targetStatus) {
                TicketStatus::IN_PROGRESS => ((int) $record->ticket_statuses_id === TicketStatus::OPEN
                    && $isAvailableToUser)
                    || ((int) $record->ticket_statuses_id === TicketStatus::IN_PROGRESS
                        && $responsible === null),
                TicketStatus::CANCEL => ((int) $record->ticket_statuses_id === TicketStatus::OPEN
                    && $isAvailableToUser)
                    || ((int) $record->ticket_statuses_id === TicketStatus::IN_PROGRESS
                        && ((int) $responsible?->getKey() === $userId || $isGlobalProcessor)),
                TicketStatus::CLOSED => (int) $record->ticket_statuses_id === TicketStatus::IN_PROGRESS
                    && ((int) $responsible?->getKey() === $userId || $isGlobalProcessor),
                default => false,
            };

            if (! $isAllowedTransition) {
                return false;
            }

            Gate::authorize('process', $record);

            $record->responsible_id = $userId;
            $record->ticket_statuses_id = $targetStatus;
            $record->save();

            return true;
        });

        if (! $updated) {
            Notification::make()
                ->title('Tiket sudah berubah atau sedang diproses petugas lain.')
                ->warning()
                ->send();

            $this->redirect($this->getResource()::getUrl('view', ['record' => $this->record->getKey()]));

            return;
        }

        Notification::make()
            ->title($successMessage)
            ->success()
            ->send();

        $this->redirect($this->getResource()::getUrl('view', ['record' => $this->record->getKey()]));
    }
}
