<?php

namespace App\Services\Integrations;

class HelpdeskIntakeGate
{
    /**
     * @var list<string>
     */
    public const TRIGGERS = [
        'buat laporan helpdesk',
        'bikin laporan helpdesk',
        'buatkan laporan helpdesk',
        'lapor',
        'buat tiket',
        'bikin tiket',
        'buatkan tiket',
        'catat tiket',
        'buka tiket',
        '/lapor',
        '/ticket',
        '/helpdesk',
        'mau lapor',
        'saya lapor',
        'create ticket',
        'open ticket',
        'submit ticket',
        'file a ticket',
        'raise a ticket',
        'report issue',
        'new ticket',
    ];

    public function detect(string $text): ?array
    {
        return $this->detectAtStart($text);
    }

    /**
     * Pemicu hanya di awal pesan. "laporan penjualan" tidak men-trigger
     * lewat kata "lapor", dan curhatan di tengah kalimat tidak memulai tiket.
     */
    public function detectAtStart(string $text): ?array
    {
        $trimmed = $this->text($text);
        if ($trimmed === '') {
            return null;
        }

        foreach ($this->sortedTriggers() as $trigger) {
            $pattern = '/^'.preg_quote($trigger, '/').'(?![a-z0-9])/iu';
            if (! preg_match($pattern, $trimmed, $matches)) {
                continue;
            }

            $remainder = $this->text(substr($trimmed, strlen($matches[0])));
            $remainder = ltrim($remainder, " \t.,:;!-");

            return [
                'trigger' => $trigger,
                'remainder' => $remainder,
            ];
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function sortedTriggers(): array
    {
        static $triggers;

        if ($triggers === null) {
            $triggers = self::TRIGGERS;
            usort($triggers, fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        }

        return $triggers;
    }

    private function text(string $value): string
    {
        return trim($value);
    }
}
