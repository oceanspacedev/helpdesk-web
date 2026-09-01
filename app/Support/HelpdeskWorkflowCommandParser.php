<?php

namespace App\Support;

use App\Enums\TicketWorkflowAction;
use Illuminate\Support\Str;

final class HelpdeskWorkflowCommandParser
{
    /** @var array<string, TicketWorkflowAction> */
    private const ACTIONS = [
        'proses' => TicketWorkflowAction::PROCESS,
        'process' => TicketWorkflowAction::PROCESS,
        'kerjakan' => TicketWorkflowAction::PROCESS,
        'handle' => TicketWorkflowAction::PROCESS,
        'claim' => TicketWorkflowAction::PROCESS,
        'done' => TicketWorkflowAction::DONE,
        'selesai' => TicketWorkflowAction::DONE,
        'close' => TicketWorkflowAction::DONE,
        'closed' => TicketWorkflowAction::DONE,
        'tutup' => TicketWorkflowAction::DONE,
    ];

    /** @var list<string> */
    private const ALLOWED_FILLERS = [
        'tolong',
        'please',
        'mohon',
        'tiket',
        'ticket',
        'nomor',
        'no',
        'ini',
        'nih',
        'ya',
        'dong',
        'sekarang',
        'langsung',
        'status',
        'ubah',
        'jadi',
        'ke',
        'di',
    ];

    /** @var list<string> */
    private const UNSAFE_WORDS = [
        'jangan',
        'jgn',
        'tidak',
        'tak',
        'bukan',
        'belum',
        'gak',
        'ga',
        'nggak',
        'ngga',
        'nanti',
        'kalau',
        'jika',
        'apakah',
        'bisakah',
        'bolehkah',
        'kenapa',
        'kapan',
    ];

    /**
     * @return array{ticket_number: string, ticket_id: int, action: TicketWorkflowAction}|null
     */
    public function parse(string $message): ?array
    {
        $message = Str::squish($message);
        if ($message === ''
            || Str::length($message) > 500
            || str_contains($message, '?')
            || preg_match('/["\'`]/u', $message)) {
            return null;
        }

        preg_match_all('/(?<![A-Z0-9])HD-[0-9]{4}-[0-9]{5,}(?![A-Z0-9])/i', $message, $ticketMatches);
        $ticketNumbers = $ticketMatches[0] ?? [];
        if (count($ticketNumbers) !== 1) {
            return null;
        }

        $reference = HelpdeskTicketNumber::parse((string) $ticketNumbers[0]);
        if ($reference === null) {
            return null;
        }

        $withoutTicket = str_ireplace((string) $ticketNumbers[0], ' ', $message);
        $normalized = Str::lower($withoutTicket);
        $normalized = preg_replace('/[^a-z0-9]+/u', ' ', $normalized) ?? '';
        $words = array_values(array_filter(explode(' ', Str::squish($normalized))));
        if ($words === [] || array_intersect($words, self::UNSAFE_WORDS) !== []) {
            return null;
        }

        $actions = [];
        foreach ($words as $word) {
            if (isset(self::ACTIONS[$word])) {
                $actions[self::ACTIONS[$word]->value] = self::ACTIONS[$word];
            }
        }
        if (count($actions) !== 1) {
            return null;
        }

        $unknownWords = array_filter(
            $words,
            fn (string $word): bool => ! isset(self::ACTIONS[$word])
                && ! in_array($word, self::ALLOWED_FILLERS, true),
        );
        if ($unknownWords !== []) {
            return null;
        }

        return [
            'ticket_number' => $reference['canonical'],
            'ticket_id' => $reference['id'],
            'action' => array_values($actions)[0],
        ];
    }
}
