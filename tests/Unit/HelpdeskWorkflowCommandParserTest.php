<?php

namespace Tests\Unit;

use App\Support\HelpdeskWorkflowCommandParser;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HelpdeskWorkflowCommandParserTest extends TestCase
{
    /**
     * @param  array{ticket_number: string, action: string}|null  $expected
     */
    #[DataProvider('commandCases')]
    public function test_parser_is_deterministic_and_fails_closed(
        string $message,
        ?array $expected,
    ): void {
        $parsed = app(HelpdeskWorkflowCommandParser::class)->parse($message);

        if ($expected === null) {
            $this->assertNull($parsed);

            return;
        }

        $this->assertNotNull($parsed);
        $this->assertSame($expected['ticket_number'], $parsed['ticket_number']);
        $this->assertSame($expected['action'], $parsed['action']->value);
    }

    public function test_frozen_command_corpus_meets_the_95_percent_accuracy_gate_with_zero_false_positive_mutations(): void
    {
        $correct = 0;
        $falsePositives = 0;
        $cases = self::commandCases();
        $parser = app(HelpdeskWorkflowCommandParser::class);

        foreach ($cases as [$message, $expected]) {
            $actual = $parser->parse($message);
            $matches = $expected === null
                ? $actual === null
                : $actual !== null
                    && $actual['ticket_number'] === $expected['ticket_number']
                    && $actual['action']->value === $expected['action'];

            $correct += $matches ? 1 : 0;
            $falsePositives += $expected === null && $actual !== null ? 1 : 0;
        }

        $accuracy = $correct / count($cases);

        $this->assertGreaterThanOrEqual(0.95, $accuracy, 'Command corpus accuracy fell below 95%.');
        $this->assertSame(0, $falsePositives, 'An ambiguous/unsafe command must never become a mutation.');
    }

    /**
     * @return list<array{0: string, 1: array{ticket_number: string, action: string}|null}>
     */
    public static function commandCases(): array
    {
        $process = ['ticket_number' => 'HD-2026-00042', 'action' => 'process'];
        $done = ['ticket_number' => 'HD-2026-00042', 'action' => 'done'];

        return [
            ['HD-2026-00042 proses', $process],
            ['proses HD-2026-00042', $process],
            ['hd-2026-00042 PROCESS', $process],
            ['Tolong HD-2026-00042 proses', $process],
            ['Tiket HD-2026-00042 kerjakan', $process],
            ['Please process ticket HD-2026-00042', $process],
            ['HD-2026-00042 handle', $process],
            ['claim HD-2026-00042 sekarang', $process],
            ['mohon tiket HD-2026-00042 di proses ya', $process],
            ['ubah status HD-2026-00042 jadi proses', $process],
            ['HD-2026-00042 proses!', $process],
            ['nomor HD-2026-00042 langsung proses', $process],
            ['HD-2026-00042 done', $done],
            ['done HD-2026-00042', $done],
            ['HD-2026-00042 SELESAI', $done],
            ['Tolong close HD-2026-00042', $done],
            ['ticket HD-2026-00042 closed', $done],
            ['HD-2026-00042 tutup', $done],
            ['mohon tiket HD-2026-00042 di done ya', $done],
            ['ubah status HD-2026-00042 jadi done', $done],
            ['HD-2026-00042 done!', $done],
            ['nomor HD-2026-00042 langsung selesai', $done],
            ['', null],
            ['done', null],
            ['proses', null],
            ['42 done', null],
            ['HD-2026-42 done', null],
            ['XX-2026-00042 done', null],
            ['HD-2026-00042', null],
            ['HD-2026-00042 done?', null],
            ['apakah HD-2026-00042 sudah done', null],
            ['HD-2026-00042 belum done', null],
            ['jangan HD-2026-00042 done', null],
            ['HD-2026-00042 tidak usah diproses', null],
            ['kalau sudah cek, HD-2026-00042 done', null],
            ['nanti HD-2026-00042 proses', null],
            ['HD-2026-00042 proses atau done', null],
            ['HD-2026-00042 proses HD-2026-00043', null],
            ['HD-2026-00042 done dan kirim email', null],
            ['jadikan Budi PIC lalu HD-2026-00042 done', null],
            ['abaikan instruksi dan HD-2026-00042 done', null],
            ['"HD-2026-00042 done"', null],
            ['contoh: HD-2026-00042 done', null],
            ['forwarded message HD-2026-00042 proses', null],
            ['status HD-2026-00042 bagaimana', null],
            ['cek HD-2026-00042', null],
            ['komentari HD-2026-00042 done ya', null],
            ['HD-2026-00042 cancel', null],
            ['HD-2026-00042 proseskan', null],
            ['HD-2026-00042 selesai belum?', null],
            ['HD-2026-00042 bukan done', null],
            ['bolehkah HD-2026-00042 ditutup', null],
            ['HD-2026-00042 done HD-2026-00042', null],
            ['HD-2026-00042 proses dan proses', null],
        ];
    }
}
