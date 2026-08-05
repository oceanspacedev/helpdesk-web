<?php

namespace App\Filament\Widgets;

use App\Models\Priority;
use App\Models\Ticket;
use App\Models\Unit;
use Carbon\Carbon;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Leandrocfe\FilamentApexCharts\Widgets\ApexChartWidget;

class SlaPerformanceChart extends ApexChartWidget
{
    use InteractsWithPageFilters;

    /**
     * Chart Id
     *
     * @var string
     */
    protected static ?string $chartId = 'slaPerformanceChart';

    /**
     * Widget Title
     *
     * @var string|null
     */
    protected static ?string $heading = 'Grafik Pencapaian SLA';

    protected static ?int $sort = 4;

    protected int | string | array $columnSpan = [
        'default' => 'full',
        'md' => 1,
        'lg' => 1,
    ];

    /**
     * Chart options (series, labels, types, size, animations...)
     * https://apexcharts.com/docs/options
     *
     * @return array
     */
    protected function getOptions(): array
    {
        $user = auth()->user();
        $isSuperAdmin = $user->hasRole('Super Admin');
        
        $categories = [];
        $seriesData = [];

        // Page Filters (Bulan & Tahun)
        $month = $this->filters['month'] ?? null;
        $year = $this->filters['year'] ?? null;

        $applyFilters = function ($query) use ($month, $year) {
            if ($year && $year !== 'all') {
                $query->whereYear('tickets.created_at', $year);
            }
            if ($month && $month !== 'all') {
                $query->whereMonth('tickets.created_at', $month);
            }
            return $query;
        };
        
        if ($isSuperAdmin) {
            $units = Unit::all();
            $metCounts = $applyFilters(Ticket::whereNotNull('sla_due_at'))->where('ticket_statuses_id', 4)->where('is_sla_met', true)->groupBy('unit_id')->selectRaw('unit_id, count(*) as total')->pluck('total', 'unit_id')->toArray();
            $closedMissedCounts = $applyFilters(Ticket::whereNotNull('sla_due_at'))->where('ticket_statuses_id', 4)->where('is_sla_met', false)->groupBy('unit_id')->selectRaw('unit_id, count(*) as total')->pluck('total', 'unit_id')->toArray();
            $activeOverdueCounts = $applyFilters(Ticket::whereNotNull('sla_due_at'))->whereNotIn('ticket_statuses_id', [3, 4])->where('sla_due_at', '<', Carbon::now())->groupBy('unit_id')->selectRaw('unit_id, count(*) as total')->pluck('total', 'unit_id')->toArray();

            foreach ($units as $unit) {
                $met = $metCounts[$unit->id] ?? 0;
                $closedMissed = $closedMissedCounts[$unit->id] ?? 0;
                $activeOverdue = $activeOverdueCounts[$unit->id] ?? 0;

                $totalEvaluated = $met + $closedMissed + $activeOverdue;

                $categories[] = $unit->name;
                if ($totalEvaluated > 0) {
                    $seriesData[] = round(($met / $totalEvaluated) * 100, 1);
                } else {
                    $seriesData[] = 0;
                }
            }
        } else {
            $userUnits = $user->units ?? collect();
            
            if ($userUnits->count() > 1) {
                $unitIds = $userUnits->pluck('id')->toArray();
                $metCounts = $applyFilters(Ticket::whereNotNull('sla_due_at'))->whereIn('unit_id', $unitIds)->where('ticket_statuses_id', 4)->where('is_sla_met', true)->groupBy('unit_id')->selectRaw('unit_id, count(*) as total')->pluck('total', 'unit_id')->toArray();
                $closedMissedCounts = $applyFilters(Ticket::whereNotNull('sla_due_at'))->whereIn('unit_id', $unitIds)->where('ticket_statuses_id', 4)->where('is_sla_met', false)->groupBy('unit_id')->selectRaw('unit_id, count(*) as total')->pluck('total', 'unit_id')->toArray();
                $activeOverdueCounts = $applyFilters(Ticket::whereNotNull('sla_due_at'))->whereIn('unit_id', $unitIds)->whereNotIn('ticket_statuses_id', [3, 4])->where('sla_due_at', '<', Carbon::now())->groupBy('unit_id')->selectRaw('unit_id, count(*) as total')->pluck('total', 'unit_id')->toArray();

                foreach ($userUnits as $unit) {
                    $met = $metCounts[$unit->id] ?? 0;
                    $closedMissed = $closedMissedCounts[$unit->id] ?? 0;
                    $activeOverdue = $activeOverdueCounts[$unit->id] ?? 0;

                    $totalEvaluated = $met + $closedMissed + $activeOverdue;

                    $categories[] = $unit->name;
                    if ($totalEvaluated > 0) {
                        $seriesData[] = round(($met / $totalEvaluated) * 100, 1);
                    } else {
                        $seriesData[] = 0;
                    }
                }
            } else {
                $priorities = Priority::all();
                $unitIds = $userUnits->pluck('id')->toArray();
                if (empty($unitIds) && $user->unit_id) {
                    $unitIds = [$user->unit_id];
                }

                $baseQuery = $applyFilters(Ticket::whereNotNull('sla_due_at'))
                    ->where(function($q) use ($user, $unitIds) {
                        if (!empty($unitIds)) {
                            $q->whereIn('unit_id', $unitIds)
                              ->orWhere('owner_id', $user->id);
                        } else {
                            $q->where('owner_id', $user->id);
                        }
                    });

                $metCounts = (clone $baseQuery)->where('ticket_statuses_id', 4)->where('is_sla_met', true)->groupBy('priority_id')->selectRaw('priority_id, count(*) as total')->pluck('total', 'priority_id')->toArray();
                $closedMissedCounts = (clone $baseQuery)->where('ticket_statuses_id', 4)->where('is_sla_met', false)->groupBy('priority_id')->selectRaw('priority_id, count(*) as total')->pluck('total', 'priority_id')->toArray();
                $activeOverdueCounts = (clone $baseQuery)->whereNotIn('ticket_statuses_id', [3, 4])->where('sla_due_at', '<', Carbon::now())->groupBy('priority_id')->selectRaw('priority_id, count(*) as total')->pluck('total', 'priority_id')->toArray();

                foreach ($priorities as $priority) {
                    $met = $metCounts[$priority->id] ?? 0;
                    $closedMissed = $closedMissedCounts[$priority->id] ?? 0;
                    $activeOverdue = $activeOverdueCounts[$priority->id] ?? 0;

                    $totalEvaluated = $met + $closedMissed + $activeOverdue;

                    $categories[] = $priority->name;
                    if ($totalEvaluated > 0) {
                        $seriesData[] = round(($met / $totalEvaluated) * 100, 1);
                    } else {
                        $seriesData[] = 0;
                    }
                }
            }
        }

        // Jika tidak ada data, beri label kosong agar grafik tidak error
        if (empty($categories)) {
            $categories[] = 'Belum Ada Data';
            $seriesData[] = 0;
        }

        return [
            'chart' => [
                'type' => 'bar',
                'height' => 300,
            ],
            'series' => [
                [
                    'name' => 'Pencapaian SLA (%)',
                    'data' => $seriesData,
                ],
            ],
            'xaxis' => [
                'categories' => $categories,
                'labels' => [
                    'style' => [
                        'colors' => '#9ca3af',
                        'fontWeight' => 600,
                    ],
                ],
            ],
            'yaxis' => [
                'max' => 100,
                'labels' => [
                    'style' => [
                        'colors' => '#9ca3af',
                    ],
                ],
            ],
            'colors' => ['#f59e0b'],
            'annotations' => [
                'yaxis' => [
                    [
                        'y' => 95,
                        'borderColor' => '#10b981',
                        'label' => [
                            'borderColor' => '#10b981',
                            'style' => [
                                'color' => '#fff',
                                'background' => '#10b981',
                            ],
                            'text' => 'Target Minimal (95%)',
                        ],
                    ],
                ],
            ],
            'plotOptions' => [
                'bar' => [
                    'borderRadius' => 4,
                    'horizontal' => false,
                ],
            ],
            'dataLabels' => [
                'enabled' => true,
            ],
        ];
    }
}
