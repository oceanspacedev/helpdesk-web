<?php

namespace App\Filament\Resources\TicketResource\Pages;

use App\Enums\TicketWorkflowAction;
use App\Filament\Resources\TicketResource;
use App\Models\ProblemCategory;
use App\Models\TicketStatus;
use App\Models\Unit;
use App\Services\Integrations\External\ExternalNotFoundException;
use App\Services\Integrations\External\ExternalTicketService;
use App\Services\Integrations\External\ExternalUnavailableException;
use App\Services\TicketWorkflowService;
use App\Support\HelpdeskTicketNumber;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Auth;
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

        // Tiket eksternal diproses di sistem tujuan: tidak ada aksi alur kerja
        // helpdesk (proses/selesai/batal/pindah unit/ubah kategori), hanya
        // muat ulang snapshot.
        if ($record->isExternal()) {
            return [$this->refreshExternalAction()];
        }

        if ($user->can('update', $record)) {
            $actions[] = Actions\EditAction::make()
                ->authorize('update');
        }

        if (! $user->can('process', $record)) {
            return $actions;
        }

        if (in_array((int) $record->ticket_statuses_id, [TicketStatus::OPEN, TicketStatus::IN_PROGRESS], true)) {
            $actions[] = $this->reclassifyAction();
            $actions[] = $this->moveUnitAction();
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
                TicketWorkflowAction::PROCESS,
                'Tiket berhasil diambil dan sedang diproses.',
            );
            $actions[] = $this->transitionAction(
                'cancel',
                'Batalkan',
                'danger',
                TicketWorkflowAction::CANCEL,
                'Tiket berhasil dibatalkan.',
            );
        } elseif ((int) $record->ticket_statuses_id === TicketStatus::IN_PROGRESS
            && $responsible === null
            && ! $isGlobalProcessor) {
            $actions[] = $this->transitionAction(
                'ambil_alih',
                'Ambil Alih',
                'primary',
                TicketWorkflowAction::PROCESS,
                'Tiket berhasil diambil alih dan dapat dilanjutkan.',
            );
        } elseif ((int) $record->ticket_statuses_id === TicketStatus::IN_PROGRESS
            && ((int) $responsible?->getKey() === (int) $user->getKey() || $isGlobalProcessor)) {
            $actions[] = $this->transitionAction(
                'selesai',
                'Selesai',
                'success',
                TicketWorkflowAction::DONE,
                'Tiket berhasil diselesaikan.',
            );
            $actions[] = $this->transitionAction(
                'cancel',
                'Batalkan',
                'danger',
                TicketWorkflowAction::CANCEL,
                'Tiket berhasil dibatalkan.',
            );
        }

        return $actions;
    }

    private function refreshExternalAction(): Actions\Action
    {
        return Actions\Action::make('muat_ulang_eksternal')
            ->label('Muat ulang dari sistem tujuan')
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->authorize('view')
            ->visible(fn (): bool => filled($this->getRecord()->external_ref) && ! $this->getRecord()->trashed())
            ->action(function (): void {
                try {
                    $applied = app(ExternalTicketService::class)->refresh($this->getRecord());
                } catch (ExternalNotFoundException) {
                    Notification::make()
                        ->title('Tiket tidak ditemukan di sistem tujuan.')
                        ->warning()
                        ->send();

                    return;
                } catch (ExternalUnavailableException $exception) {
                    report($exception);
                    Notification::make()
                        ->title('Sistem tujuan sedang tidak tersedia. Coba muat ulang beberapa saat lagi.')
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title($applied ? 'Data tiket diperbarui dari sistem tujuan.' : 'Data di helpdesk sudah yang terbaru.')
                    ->success()
                    ->send();

                $this->redirect($this->getResource()::getUrl('view', ['record' => $this->getRecord()->getKey()]));
            });
    }

    private function reclassifyAction(): Actions\Action
    {
        return Actions\Action::make('ubah_kategori')
            ->label('Ubah kategori')
            ->color('gray')
            ->authorize('process')
            ->form([
                Forms\Components\Select::make('problem_category_id')
                    ->label('Kategori')
                    ->options(fn (): array => ProblemCategory::query()
                        ->where('unit_id', $this->getRecord()->unit_id)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->required()
                    ->helperText('Untuk pindah IT ↔ BUSDEV, gunakan Pindah unit.')
                    ->default(fn () => $this->getRecord()->problem_category_id),
            ])
            ->action(function (array $data): void {
                $record = $this->getRecord();
                $record->problem_category_id = (int) $data['problem_category_id'];
                $record->save();

                Notification::make()
                    ->title('Kategori tiket diperbarui.')
                    ->success()
                    ->send();
            });
    }

    private function moveUnitAction(): Actions\Action
    {
        return Actions\Action::make('pindah_unit')
            ->label('Pindah unit')
            ->color('gray')
            ->authorize('process')
            ->form([
                Forms\Components\Select::make('unit_id')
                    ->label('Unit tujuan')
                    // Unit berintegrasi hanya menerima tiket lewat form
                    // sistem eksternal, bukan pindahan tiket helpdesk.
                    ->options(fn (): array => Unit::query()
                        ->acceptingTickets()
                        ->internal()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->required()
                    ->live()
                    ->default(fn () => $this->getRecord()->unit_id),
                Forms\Components\Select::make('problem_category_id')
                    ->label('Kategori di unit baru')
                    ->options(function (Get $get): array {
                        $unitId = (int) ($get('unit_id') ?: $this->getRecord()->unit_id);

                        return ProblemCategory::query()
                            ->where('unit_id', $unitId)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all();
                    })
                    ->required(),
            ])
            ->action(function (array $data): void {
                $record = $this->getRecord();
                $unitId = (int) $data['unit_id'];
                $category = ProblemCategory::query()->find($data['problem_category_id']);
                if (! Unit::query()->internal()->whereKey($unitId)->exists()) {
                    Notification::make()
                        ->title('Unit tujuan memakai sistem eksternal; tiket tidak bisa dipindah ke sana.')
                        ->danger()
                        ->send();

                    return;
                }

                if (! $category || (int) $category->unit_id !== $unitId) {
                    Notification::make()
                        ->title('Kategori tidak termasuk unit tujuan.')
                        ->danger()
                        ->send();

                    return;
                }

                $record->unit_id = $unitId;
                $record->problem_category_id = (int) $category->id;
                $record->responsible_id = null;
                if ((int) $record->ticket_statuses_id === TicketStatus::IN_PROGRESS) {
                    $record->ticket_statuses_id = TicketStatus::OPEN;
                }
                $record->save();

                Notification::make()
                    ->title('Tiket dipindah ke '.($record->unit?->name ?: 'unit baru').'.')
                    ->success()
                    ->send();

                $user = Auth::user();
                if ($user && Gate::forUser($user)->allows('view', $record->fresh())) {
                    $this->redirect($this->getResource()::getUrl('view', ['record' => $record->getKey()]));

                    return;
                }

                $this->redirect($this->getResource()::getUrl('index'));
            });
    }

    private function transitionAction(
        string $name,
        string $label,
        string $color,
        TicketWorkflowAction $action,
        string $successMessage,
    ): Actions\Action {
        return Actions\Action::make($name)
            ->label($label)
            ->color($color)
            ->authorize('process')
            ->action(fn () => $this->transitionTicket($action, $successMessage));
    }

    private function transitionTicket(TicketWorkflowAction $action, string $successMessage): void
    {
        $user = Auth::user();
        $result = app(TicketWorkflowService::class)->transition(
            HelpdeskTicketNumber::format($this->getRecord()),
            $user,
            $action,
        );

        if (! $result['ok']) {
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
