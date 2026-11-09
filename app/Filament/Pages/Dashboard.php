<?php

namespace App\Filament\Pages;

use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BasePage;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;

class Dashboard extends BasePage
{
    use HasFiltersForm;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-home';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'heroicon-s-home';

    /**
     * Satu kolom di layar sempit, dua kolom mulai lebar lg agar grafik
     * status tiket dan distribusi SLA bisa berdampingan.
     */
    public function getColumns(): int|array
    {
        return [
            'default' => 1,
            'lg' => 2,
        ];
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->columns([
                'default' => 1,
                'sm' => 2,
            ])
            ->components([
                Select::make('month')
                    ->label(__('Bulan'))
                    ->options([
                        'all' => 'Semua Bulan',
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
                    ->default((int) date('n')),
                Select::make('year')
                    ->label(__('Tahun'))
                    ->options(function () {
                        $currentYear = (int) date('Y');
                        $years = ['all' => 'Semua Tahun'];
                        for ($y = 2024; $y <= $currentYear + 1; $y++) {
                            $years[$y] = $y;
                        }

                        return $years;
                    })
                    ->default((int) date('Y')),
            ]);
    }
}
