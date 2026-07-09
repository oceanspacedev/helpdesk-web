<?php

namespace App\Filament\Widgets;

use App\Models\TicketStatus;
use Leandrocfe\FilamentApexCharts\Widgets\ApexChartWidget;

class TicketStatusesChart extends ApexChartWidget
{
    /**
     * Chart Id
     *
     * @var string
     */
    protected static ?string $chartId = 'ticketStatusesChart';

    /**
     * Widget Title
     *
     * @var string|null
     */
    protected static ?string $heading = 'Ticket Statuses';

    /**
     * Chart options (series, labels, types, size, animations...)
     * https://apexcharts.com/docs/options
     *
     * @return array
     */
    protected function getOptions(): array
    {
        $user = auth()->user();
        $ticketStatusesQuery = TicketStatus::select('id', 'name')
            ->withCount(['tickets' => function ($query) use ($user) {
                if (!$user->hasRole('Super Admin')) {
                    $query->where('unit_id', $user->unit_id)
                    ->orWhere('owner_id', $user->id);
                }
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
