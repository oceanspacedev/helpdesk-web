<?php

namespace Tests\Feature;

use App\Models\BusinessEntity;
use App\Models\Priority;
use App\Services\Integrations\HelpdeskClassificationResolver;
use App\Services\Integrations\HelpdeskIntakeGate;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\CreatesHelpdeskIntegrationSchema;
use Tests\TestCase;

class HelpdeskIntakeChaosEvalTest extends TestCase
{
    use CreatesHelpdeskIntegrationSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        Cache::flush();
    }

    public function test_trigger_gate_error_profile_on_natural_chat_corpus(): void
    {
        $gate = app(HelpdeskIntakeGate::class);

        $shouldStart = [
            'lapor',
            'Lapor',
            'LAPOR!',
            'lapor.',
            'lapor odoo error',
            'mau lapor wifi mati',
            'saya lapor printer kasir',
            'buat tiket',
            'bikin tiket',
            'buatkan tiket email',
            '/lapor',
            '/ticket',
            '/helpdesk',
            'catat tiket',
            'buka tiket',
            'lapor: odoo gabisa invoice',
            'buat laporan helpdesk',
            'Buat laporan helpdesk: printer kasir',
        ];

        $shouldIgnore = [
            'odoo gabisa invoice urgent',
            'Odoo Complete Selular gabisa invoice, urgent',
            'bos printer error',
            'min wifi mati',
            'tolong dicek',
            'admin email saya locked',
            'ok',
            'ya',
            '2',
            'yang kedua',
            'cek tiket 42',
            'status tiket',
            'buat invoice',
            'buka odoo',
            'ticket konser',
            'report bug',
            'help',
            'hd',
            'lpor',
            'laporr',
            'tiket dong',
            'buatkan',
            '',
            '👍',
        ];

        $falseStartSeeds = [
            'kirim laporan penjualan ke pak budi',
            'laporan stok belum keluar',
            'helpdesk kemarin sudah bantu',
            'tim helpdesk ramah',
        ];

        $falseNegatives = 0;
        foreach ($this->explodeCorpus($shouldStart, 4000) as $text) {
            if ($gate->detect($text) === null) {
                $falseNegatives++;
            }
        }

        $falsePositivesNoise = 0;
        foreach ($this->explodeCorpus($shouldIgnore, 4000) as $text) {
            if ($gate->detect($text) !== null) {
                $falsePositivesNoise++;
            }
        }

        $falsePositivesEveryday = 0;
        foreach ($this->explodeCorpus($falseStartSeeds, 2000) as $text) {
            if ($gate->detect($text) !== null) {
                $falsePositivesEveryday++;
            }
        }

        $startN = 4000 * count($shouldStart);
        $ignoreN = 4000 * count($shouldIgnore);
        $everydayN = 2000 * count($falseStartSeeds);

        $this->assertSame(0, $falseNegatives, "Canonical triggers must always fire. misses={$falseNegatives}/{$startN}");
        $this->assertSame(0, $falsePositivesNoise, "Complaints without trigger must not start intake. hits={$falsePositivesNoise}/{$ignoreN}");
        $this->assertSame(0, $falsePositivesEveryday, "Everyday uses of laporan/helpdesk must not start intake. hits={$falsePositivesEveryday}/{$everydayN}");
    }

    public function test_one_million_mixed_messages_keep_complaints_out_and_canonical_triggers_in(): void
    {
        $gate = app(HelpdeskIntakeGate::class);
        $rng = 20260828;
        $next = static function () use (&$rng): int {
            $rng = (1103515245 * $rng + 12345) & 0x7FFFFFFF;

            return $rng;
        };

        $complaints = [
            'odoo gabisa invoice',
            'printer kasir error',
            'wifi mati',
            'email locked',
            'cctv tidak tampil',
            'lpor printer',
            'tiket dong',
            'tolong dicek',
            '2',
            'ok',
        ];
        $triggers = [
            'lapor',
            'buat tiket',
            '/lapor',
            'mau lapor',
        ];

        $complaintHits = 0;
        $triggerMisses = 0;
        $n = 1_000_000;

        for ($i = 0; $i < $n; $i++) {
            if (($next() % 10) < 8) {
                $text = $complaints[$next() % count($complaints)];
                if ($next() % 2 === 0) {
                    $text = strtoupper($text);
                }
                if ($gate->detect($text) !== null) {
                    $complaintHits++;
                }
            } else {
                $text = $triggers[$next() % count($triggers)];
                if ($next() % 3 === 0) {
                    $text .= ' odoo error';
                }
                if ($gate->detect($text) === null) {
                    $triggerMisses++;
                }
            }
        }

        $this->assertSame(0, $complaintHits, "1e6 mixed: complaints leaked into intake: {$complaintHits}");
        $this->assertSame(0, $triggerMisses, "1e6 mixed: canonical triggers missed: {$triggerMisses}");
    }

    public function test_prefill_does_not_guess_when_master_data_is_absent_from_chat(): void
    {
        BusinessEntity::create(['name' => 'Complete Selular']);
        BusinessEntity::create(['name' => 'Top Selular']);
        Priority::create(['id' => Priority::MEDIUM, 'name' => 'Medium']);
        $resolver = app(HelpdeskClassificationResolver::class);

        $result = $resolver->prefillFromMessage('odoo gabisa buat invoice dari tadi pagi');

        $this->assertSame([], $result['matched']);
        $this->assertContains('business_entities_id', $result['missing_fields']);
        $this->assertContains('priority_id', $result['missing_fields']);
        $this->assertSame([], $result['ambiguous']);
    }

    public function test_prefill_does_not_guess_priority_from_urgency_slang(): void
    {
        BusinessEntity::create(['name' => 'Complete Selular']);
        Priority::create(['id' => Priority::MEDIUM, 'name' => 'Medium']);
        Priority::create(['id' => Priority::CRITICAL, 'name' => 'Critical']);
        $resolver = app(HelpdeskClassificationResolver::class);

        $urgent = $resolver->prefillFromMessage('lapor odoo gabisa invoice urgent');
        $sedang = $resolver->prefillFromMessage('wifi sedang mati');

        $this->assertSame([], $urgent['matched']);
        $this->assertContains('priority_id', $urgent['missing_fields']);
        $this->assertSame([], $sedang['matched']);
        $this->assertContains('priority_id', $sedang['missing_fields']);
    }

    /**
     * @param  list<string>  $seeds
     * @return \Generator<int, string>
     */
    private function explodeCorpus(array $seeds, int $perSeed): \Generator
    {
        $wrappers = [
            '%s',
            '%s ',
            '%s!!',
            '%s.',
            '%s???',
        ];

        $i = 0;
        foreach ($seeds as $seed) {
            for ($n = 0; $n < $perSeed; $n++) {
                $text = sprintf($wrappers[$n % count($wrappers)], $seed);
                if ($n % 7 === 0) {
                    $text = mb_strtoupper($text);
                }
                yield $i++ => $text;
            }
        }
    }
}
