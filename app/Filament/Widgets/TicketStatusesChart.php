<?php

namespace App\Filament\Widgets;

use App\Models\TicketStatus;
use App\Support\DashboardPeriod;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Leandrocfe\FilamentApexCharts\Widgets\ApexChartWidget;

class TicketStatusesChart extends ApexChartWidget
{
    use InteractsWithPageFilters;

    /**
     * Chart Id
     */
    protected static ?string $chartId = 'ticketStatusesChart';

    /**
     * Widget Title
     */
    protected static ?string $heading = 'Ticket Statuses';

    protected static bool $deferLoading = true;

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = [
        'default' => 'full',
        'md' => 1,
        'lg' => 1,
    ];

    /**
     * Chart options (series, labels, types, size, animations...)
     * https://apexcharts.com/docs/options
     */
    protected function getOptions(): array
    {
        $user = auth()->user();

        // Page Filters (Bulan & Tahun)
        $month = $this->filters['month'] ?? null;
        $year = $this->filters['year'] ?? null;

        $ticketStatusesQuery = TicketStatus::select('id', 'name')
            ->withCount(['tickets' => function ($query) use ($user, $month, $year) {
                DashboardPeriod::apply($query->visibleTo($user), $month, $year);
            }]);

        $ticketStatuses = $ticketStatusesQuery->get();

        return [
            'chart' => [
                'type' => 'pie',
                'height' => 300,
            ],
            'series' => $ticketStatuses->pluck('tickets_count')->toArray(),
            'labels' => $ticketStatuses->pluck('name')->toArray(),
            'legend' => [
                'labels' => [
                    'colors' => '#9ca3af',
                    'fontWeight' => 600,
                ],
            ],
        ];
    }
}
