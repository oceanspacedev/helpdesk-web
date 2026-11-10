<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Support\DashboardPeriod;
use Illuminate\Database\Eloquent\Builder;
use Tests\TestCase;

class DashboardPeriodTest extends TestCase
{
    public function test_month_and_year_become_a_half_open_range_on_the_raw_column(): void
    {
        $query = DashboardPeriod::apply(Ticket::query(), 11, '2026');

        $this->assertStringContainsString('"tickets"."created_at" >= ?', $this->normalize($query->toSql()));
        $this->assertStringContainsString('"tickets"."created_at" < ?', $this->normalize($query->toSql()));
        $this->assertStringNotContainsString('strftime', $query->toSql());
        $this->assertStringNotContainsString('year(', strtolower($query->toSql()));
        $this->assertSame(['2026-11-01 00:00:00', '2026-12-01 00:00:00'], $this->bindings($query));
    }

    public function test_year_alone_covers_the_whole_year(): void
    {
        $query = DashboardPeriod::apply(Ticket::query(), 'all', 2026);

        $this->assertSame(['2026-01-01 00:00:00', '2027-01-01 00:00:00'], $this->bindings($query));
    }

    public function test_month_without_year_falls_back_to_where_month_and_all_means_no_filter(): void
    {
        $monthOnly = DashboardPeriod::apply(Ticket::query(), 3, 'all');
        $this->assertCount(1, $monthOnly->getQuery()->wheres);
        $this->assertSame(['03'], $this->bindings($monthOnly));

        $unfiltered = DashboardPeriod::apply(Ticket::query(), 'all', 'all');
        $this->assertSame([], $unfiltered->getQuery()->wheres);

        $garbage = DashboardPeriod::apply(Ticket::query(), 13, 'abc');
        $this->assertSame([], $garbage->getQuery()->wheres);
    }

    /** @return list<string> */
    private function bindings(Builder $query): array
    {
        return array_map(
            fn (mixed $value): string => $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : (string) $value,
            $query->getBindings(),
        );
    }

    private function normalize(string $sql): string
    {
        return str_replace('`', '"', $sql);
    }
}
