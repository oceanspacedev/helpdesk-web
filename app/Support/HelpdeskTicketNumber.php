<?php

namespace App\Support;

use App\Models\Ticket;

final class HelpdeskTicketNumber
{
    public const PATTERN = '^HD-[0-9]{4}-[0-9]{5,}$';

    public static function format(Ticket $ticket): string
    {
        $year = $ticket->created_at?->format('Y') ?: now()->format('Y');

        return 'HD-'.$year.'-'.str_pad((string) $ticket->getKey(), 5, '0', STR_PAD_LEFT);
    }

    /**
     * @return array{canonical: string, year: int, id: int}|null
     */
    public static function parse(string $value): ?array
    {
        $canonical = strtoupper(trim($value));
        if (! preg_match('/\AHD-([0-9]{4})-([0-9]{5,})\z/D', $canonical, $matches)) {
            return null;
        }

        $idText = ltrim($matches[2], '0');
        $idText = $idText === '' ? '0' : $idText;
        $id = filter_var($idText, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if (! is_int($id)) {
            return null;
        }

        return [
            'canonical' => $canonical,
            'year' => (int) $matches[1],
            'id' => $id,
        ];
    }
}
