<?php

namespace App\Filament\Resources\TicketResource\Pages;

use App\Filament\Resources\TicketResource;
use App\Models\Ticket;
use App\Models\TicketStatus;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use pxlrbt\FilamentExcel\Columns\Column;
use pxlrbt\FilamentExcel\Exports\ExcelExport;

class ListTickets extends ListRecords
{
    protected static string $resource = TicketResource::class;

    public function getTabs(): array
    {
        $user = auth()->user();

        if (! $user) {
            return [];
        }

        $outgoing = Tab::make('Tiket Keluar')
            ->icon('heroicon-o-paper-airplane')
            ->badge(fn (): int => Ticket::query()
                ->outgoingFor($user)
                ->where('ticket_statuses_id', TicketStatus::OPEN)
                ->count())
            ->badgeTooltip('Tiket keluar yang masih Open')
            ->query(fn (Builder $query): Builder => $query->outgoingFor($user));

        if ($user->hasGlobalTicketAccess()) {
            return [
                'semua' => Tab::make('Semua Tiket')
                    ->icon('heroicon-o-rectangle-stack')
                    ->badge(fn (): int => Ticket::query()
                        ->where('ticket_statuses_id', TicketStatus::OPEN)
                        ->count())
                    ->badgeTooltip('Semua tiket yang masih Open'),
                'keluar' => $outgoing,
            ];
        }

        if (! $user->canProcessTickets()) {
            return ['keluar' => $outgoing];
        }

        return [
            'masuk' => Tab::make('Tiket Masuk')
                ->icon('heroicon-o-inbox-arrow-down')
                ->badge(fn (): int => Ticket::query()
                    ->incomingFor($user)
                    ->where('ticket_statuses_id', TicketStatus::OPEN)
                    ->count())
                ->badgeTooltip('Tiket masuk yang masih Open')
                ->query(fn (Builder $query): Builder => $query->incomingFor($user)),
            'keluar' => $outgoing,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('export')
                ->label('Export Tiket')
                ->icon('heroicon-o-document-arrow-down')
                ->color('success')
                ->modalHeading('Export Data Tiket per Bulan')
                ->modalDescription('File XLSX mengikuti scope, pencarian, dan filter pada tab tiket yang sedang aktif.')
                ->modalSubmitActionLabel('Unduh XLSX')
                ->modalWidth('md')
                ->form([
                    Forms\Components\Select::make('month')
                        ->label(__('Bulan'))
                        ->options([
                            1 => 'Januari',
                            2 => 'Februari',
                            3 => 'Maret',
                            4 => 'April',
                            5 => 'Mei',
                            6 => 'Juni',
                            7 => 'Juli',
                            8 => 'Agustus',
                            9 => 'September',
                            10 => 'Oktober',
                            11 => 'November',
                            12 => 'Desember',
                        ])
                        ->default((int) date('n'))
                        ->required(),
                    Forms\Components\Select::make('year')
                        ->label(__('Tahun'))
                        ->options(function () {
                            $currentYear = (int) date('Y');
                            $years = [];
                            for ($y = 2024; $y <= $currentYear + 1; $y++) {
                                $years[$y] = $y;
                            }

                            return $years;
                        })
                        ->default((int) date('Y'))
                        ->required(),
                ])
                ->action(function (array $data, ListTickets $livewire) {
                    $month = (int) $data['month'];
                    $year = (int) $data['year'];

                    $export = ExcelExport::make()
                        ->fromTable()
                        ->withFilename('export_ticket_'.$year.'_'.str_pad($month, 2, '0', STR_PAD_LEFT))
                        ->modifyQueryUsing(function ($query) use ($month, $year) {
                            return $query->whereYear('tickets.created_at', $year)
                                ->whereMonth('tickets.created_at', $month);
                        })
                        ->withColumns([
                            Column::make('priority.name')->heading('Level Tiket'),
                            Column::make('unit.name')->heading('Divisi'),
                            Column::make('problemCategory.name')->heading('Kategori Tiket'),
                            Column::make('owner.name')->heading('Pemilik Tiket'),
                            Column::make('title')->heading('Judul'),
                            Column::make('businessEntity.name')->heading('Badan Usaha'),
                            Column::make('ticketStatus.name')->heading('Status Tiket'),
                            Column::make('responsible.name')->heading('Penanggung Jawab'),
                            Column::make('created_at')->heading('Tanggal Dibuat'),
                        ]);

                    return app()->call([$export, 'hydrate'], [
                        'livewire' => $livewire,
                    ])->export();
                }),
            Actions\CreateAction::make(),
        ];
    }
}
