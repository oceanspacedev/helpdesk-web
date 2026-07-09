<?php

namespace App\Filament\Resources\TicketResource\Pages;

use App\Filament\Resources\TicketResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use pxlrbt\FilamentExcel\Actions\Pages\ExportAction;
use pxlrbt\FilamentExcel\Exports\ExcelExport;
use pxlrbt\FilamentExcel\Columns\Column;

class ListTickets extends ListRecords
{
    protected static string $resource = TicketResource::class;

    protected function getActions(): array
    {
        return [
            ExportAction::make('export')
            ->label('Export')
            ->icon('heroicon-o-document-download')
            ->color('success')
            ->exports([
                ExcelExport::make()->fromTable()->withColumns([
                    Column::make('priority.name')->heading('Level Tiket'),
                    Column::make('unit.name')->heading('Divisi'),
                    Column::make('problemCategory.name')->heading('Kategori Tiket'),
                    Column::make('owner.name')->heading('Pemilik Tiket'),
                    Column::make('title')->heading('Judul'),
                    Column::make('businessEntity.name')->heading('Badan Usaha'),
                    Column::make('ticketStatus.name')->heading('Status Tiket'),
                    Column::make('responsible.name')->heading('Penanggung Jawab'),
                ])->withFilename('export_ticket_' . date('Y-m-d')),
            ]),
            Actions\CreateAction::make(),
        ];
    }
}
