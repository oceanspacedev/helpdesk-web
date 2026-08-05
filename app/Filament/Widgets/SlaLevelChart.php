<?php

namespace App\Filament\Widgets;

use App\Models\Priority;
use App\Models\Ticket;
use Carbon\Carbon;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Leandrocfe\FilamentApexCharts\Widgets\ApexChartWidget;

class SlaLevelChart extends ApexChartWidget
{
    use InteractsWithPageFilters;

    /**
     * Chart Id
     *
     * @var string
     */
    protected static ?string $chartId = 'slaLevelChart';

    /**
     * Widget Title
     *
     * @var string|null
     */
    protected static ?string $heading = 'Distribusi Status SLA per Prioritas';

    protected static ?int $sort = 5;

    protected int | string | array $columnSpan = 'full';

    /**
     * Chart options (series, labels, types, size, animations...)
     * https://apexcharts.com/docs/options
     *
     * @return array
     */
    protected function getOptions(): array
    {
        $user = auth()->user();
        $isGlobalAdmin = $user->hasAnyRole(['Super Admin', 'Master Admin']);
        
        $priorities = Priority::all();
        $categories = [];
        $achievedData = [];
        $missedData = [];
        $pendingData = [];
        
        $userUnits = $user->units ?? collect();
        $unitIds = $userUnits->pluck('id')->toArray();
        if (empty($unitIds) && $user->unit_id) {
            $unitIds = [$user->unit_id];
        }

        $baseScope = Ticket::query();

        // Page Filters (Bulan & Tahun)
        $month = $this->filters['month'] ?? null;
        $year = $this->filters['year'] ?? null;

        if ($year && $year !== 'all') {
            $baseScope->whereYear('tickets.created_at', $year);
        }
        if ($month && $month !== 'all') {
            $baseScope->whereMonth('tickets.created_at', $month);
        }

        if (!$isGlobalAdmin) {
            $baseScope->where(function($q) use ($user, $unitIds) {
                if (!empty($unitIds)) {
                    $q->whereIn('unit_id', $unitIds)
                      ->orWhere('owner_id', $user->id);
                } else {
                    $q->where('owner_id', $user->id);
                }
            });
        }

        // 1. Achieved: is_sla_met = true
        $achievedCounts = (clone $baseScope)->where('is_sla_met', true)
            ->groupBy('priority_id')
            ->selectRaw('priority_id, count(*) as total')
            ->pluck('total', 'priority_id')
            ->toArray();

        // 2. Missed: is_sla_met = false OR (belum selesai, bukan cancelled, dan waktu sudah habis)
        $missedCounts = (clone $baseScope)->where(function($q) {
            $q->where('is_sla_met', false)
              ->orWhere(function($sub) {
                  $sub->whereNull('is_sla_met')
                      ->whereNotIn('ticket_statuses_id', [3, 4])
                      ->whereNotNull('sla_due_at')
                      ->where('sla_due_at', '<', Carbon::now());
              });
        })
        ->groupBy('priority_id')
        ->selectRaw('priority_id, count(*) as total')
        ->pluck('total', 'priority_id')
        ->toArray();

        // 3. Pending: belum selesai, bukan cancelled, dan waktu masih ada
        $pendingCounts = (clone $baseScope)->whereNull('is_sla_met')
            ->whereNotIn('ticket_statuses_id', [3, 4])
            ->whereNotNull('sla_due_at')
            ->where('sla_due_at', '>=', Carbon::now())
            ->groupBy('priority_id')
            ->selectRaw('priority_id, count(*) as total')
            ->pluck('total', 'priority_id')
            ->toArray();

        foreach ($priorities as $priority) {
            $categories[] = $priority->name;
            $achievedData[] = $achievedCounts[$priority->id] ?? 0;
            $missedData[] = $missedCounts[$priority->id] ?? 0;
            $pendingData[] = $pendingCounts[$priority->id] ?? 0;
        }

        return [
            'chart' => [
                'type' => 'bar',
                'height' => 300,
                'stacked' => true,
                'toolbar' => [
                    'show' => true
                ],
                'zoom' => [
                    'enabled' => true
                ]
            ],
            'series' => [
                [
                    'name' => 'Achieved',
                    'data' => $achievedData,
                ],
                [
                    'name' => 'Missed',
                    'data' => $missedData,
                ],
                [
                    'name' => 'Pending',
                    'data' => $pendingData,
                ],
            ],
            'xaxis' => [
                'categories' => $categories,
                'labels' => [
                    'style' => [
                        'colors' => '#9ca3af',
                        'fontWeight' => 500,
                    ],
                ],
            ],
            'yaxis' => [
                'labels' => [
                    'style' => [
                        'colors' => '#9ca3af',
                    ],
                ],
            ],
            'colors' => ['#10b981', '#ef4444', '#f59e0b'],
            'plotOptions' => [
                'bar' => [
                    'horizontal' => false,
                    'borderRadius' => 2,
                    'columnWidth' => '40%',
                ],
            ],
            'dataLabels' => [
                'enabled' => true,
            ],
            'legend' => [
                'position' => 'bottom',
                'labels' => [
                    'colors' => '#9ca3af',
                ],
            ],
            'fill' => [
                'opacity' => 1
            ],
        ];
    }
}
