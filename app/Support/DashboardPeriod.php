<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Filter bulan/tahun dashboard sebagai rentang tanggal.
 *
 * whereYear()/whereMonth() membungkus kolom dalam fungsi sehingga indeks
 * created_at tidak terpakai dan seluruh tabel tiket dipindai. Rentang
 * [awal, akhir) memakai indeks. Hanya "bulan tertentu di semua tahun" yang
 * tidak bisa jadi satu rentang; untuk itu whereMonth tetap dipakai.
 */
final class DashboardPeriod
{
    /**
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function apply(Builder $query, mixed $month, mixed $year, string $column = 'tickets.created_at'): Builder
    {
        $month = self::normalize($month, 1, 12);
        $year = self::normalize($year, 2000, 2999);

        if ($year === null) {
            return $month === null ? $query : $query->whereMonth($column, $month);
        }

        $start = CarbonImmutable::create($year, $month ?? 1, 1)->startOfDay();
        $end = $month === null ? $start->addYear() : $start->addMonth();

        return $query
            ->where($column, '>=', $start)
            ->where($column, '<', $end);
    }

    private static function normalize(mixed $value, int $min, int $max): ?int
    {
        if ($value === null || $value === '' || $value === 'all' || ! is_numeric($value)) {
            return null;
        }

        $value = (int) $value;

        return $value >= $min && $value <= $max ? $value : null;
    }
}
