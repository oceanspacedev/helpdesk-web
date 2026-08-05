<?php

namespace App\Filament\Widgets;

use App\Models\Ticket;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SlaPerformanceWidget extends BaseWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 1;

    protected int | string | array $columnSpan = 'full';

    protected function getStats(): array
    {
        $user = auth()->user();
        $isGlobalAdmin = $user->hasRole('Super Admin');
        
        // Base scope for tickets with SLA configured
        $baseQuery = Ticket::whereNotNull('sla_due_at');

        // Apply Page Filters (Bulan & Tahun)
        $month = $this->filters['month'] ?? null;
        $year = $this->filters['year'] ?? null;

        if ($year && $year !== 'all') {
            $baseQuery->whereYear('tickets.created_at', $year);
        }
        if ($month && $month !== 'all') {
            $baseQuery->whereMonth('tickets.created_at', $month);
        }
        
        if (!$isGlobalAdmin) {
            $userUnits = $user->units ?? collect();
            $unitIds = $userUnits->pluck('id')->toArray();
            if (empty($unitIds) && $user->unit_id) {
                $unitIds = [$user->unit_id];
            }
            
            $baseQuery->where(function($q) use ($user, $unitIds) {
                if (!empty($unitIds)) {
                    $q->whereIn('unit_id', $unitIds)
                      ->orWhere('owner_id', $user->id);
                } else {
                    $q->where('owner_id', $user->id);
                }
            });
        }
        
        // 1. Tiket Closed tepat waktu
        $metClosed = (clone $baseQuery)->where('ticket_statuses_id', 4)->where('is_sla_met', true)->count();
        
        // 2. Tiket Closed yang telat
        $missedClosed = (clone $baseQuery)->where('ticket_statuses_id', 4)->where('is_sla_met', false)->count();
        
        // 3. Tiket Aktif yang SUDAH LEWAT batas SLA (Overdue Active) - Kecualikan Cancelled (3) & Closed (4)
        $activeOverdue = (clone $baseQuery)->whereNotIn('ticket_statuses_id', [3, 4])
            ->where('sla_due_at', '<', \Carbon\Carbon::now())
            ->count();

        // Total evaluasi SLA = Tiket Selesai + Tiket Aktif yang sudah telat
        $totalEvaluated = $metClosed + $missedClosed + $activeOverdue;
        
        $percentage = $totalEvaluated > 0 ? round(($metClosed / $totalEvaluated) * 100, 1) : 0;
        
        return [
            Stat::make(__('SLA Target Achievement'), $percentage . '%')
                ->description(__('SLA Met: :met of :total evaluated tickets (Target: ≥95%)', ['met' => $metClosed, 'total' => $totalEvaluated]))
                ->descriptionIcon($percentage >= 95 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($percentage >= 95 ? 'success' : 'danger'),

            Stat::make(__('Active Overdue SLA'), $activeOverdue . ' ' . __('Tickets'))
                ->description(__('Active tickets that exceeded SLA limit'))
                ->descriptionIcon($activeOverdue > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-badge')
                ->color($activeOverdue > 0 ? 'danger' : 'success'),
        ];
    }
}
