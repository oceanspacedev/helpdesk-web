<?php

namespace Tests\Feature;

use App\Models\BusinessEntity;
use App\Models\Priority;
use App\Models\ProblemCategory;
use App\Models\Unit;
use App\Services\Integrations\HelpdeskOperationalClassifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesHelpdeskIntegrationSchema;
use Tests\TestCase;

class HelpdeskOperationalClassifierTest extends TestCase
{
    use CreatesHelpdeskIntegrationSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        Cache::flush();

        $it = Unit::create(['name' => 'IT']);
        Unit::create(['name' => 'BUSDEV']);
        ProblemCategory::create(['unit_id' => $it->id, 'name' => 'Odoo Program']);
        ProblemCategory::create(['unit_id' => $it->id, 'name' => 'Laptop, Komputer, Printer']);
        ProblemCategory::create(['unit_id' => $it->id, 'name' => 'CSA Program']);
        ProblemCategory::create(['unit_id' => $it->id, 'name' => 'CCTV']);
        ProblemCategory::create(['unit_id' => $it->id, 'name' => 'Jaringan']);
        Priority::create(['name' => 'Critical/Urgent']);
        Priority::create(['name' => 'Medium']);
        BusinessEntity::create(['name' => 'CV. CS']);
        BusinessEntity::create(['name' => 'Complete Kulinari']);
        BusinessEntity::create(['name' => 'CV. TOP']);
        BusinessEntity::create(['name' => 'CV. MAJU TECNOLOGI']);
    }

    public function test_strips_atlas_and_lapor_prefixes_from_title(): void
    {
        $classifier = app(HelpdeskOperationalClassifier::class);

        $atlas = $classifier->normalizeIssue('buat laporan helpdesk printer tidak bisa ngeprint ruang it');
        $this->assertSame('Printer tidak bisa ngeprint ruang it', $atlas['title']);
        $this->assertSame('Ruang it', $atlas['location']);
        $this->assertStringNotContainsString('buat laporan', Str::lower($atlas['title']));

        $lapor = $classifier->normalizeIssue('lapor printer kasir tidak bisa mencetak sejak pagi');
        $this->assertSame('Printer kasir tidak bisa mencetak sejak pagi', $lapor['title']);
        $this->assertSame('Kasir', $lapor['location']);
    }

    public function test_routes_live_helpdesk_language_without_llm_choices(): void
    {
        $classifier = app(HelpdeskOperationalClassifier::class);

        $printer = $classifier->classify('lapor printer kasir tidak bisa mencetak sejak pagi');
        $this->assertSame('Laptop, Komputer, Printer', $printer['problem_category']);
        $this->assertSame('IT', $printer['unit']);
        $this->assertSame('Medium', $printer['priority']);
        $this->assertTrue($printer['priority_assumed'] ?? false);
        $this->assertSame(HelpdeskOperationalClassifier::UNSTATED_ENTITY, $printer['business_entity']);
        $this->assertTrue($printer['entity_assumed'] ?? false);
        $this->assertSame('Printer / komputer', $printer['affected_system']);
        $this->assertNotSame('CCTV', $printer['problem_category']);
        $this->assertNotSame('Complete Kulinari', $printer['business_entity']);

        $named = $classifier->classify('lapor printer kasir complete selular tidak bisa mencetak');
        $this->assertSame('CV. CS', $named['business_entity']);
        $this->assertFalse($named['entity_assumed'] ?? false);

        $odoo = $classifier->classify('Odoo tidak bisa login');
        $this->assertSame('Odoo Program', $odoo['problem_category']);

        $cctv = $classifier->classify('Penambahan CCTV toko tegal');
        $this->assertSame('CCTV', $cctv['problem_category']);

        $wifi = $classifier->classify('VPN kantor putus');
        $this->assertSame('Jaringan', $wifi['problem_category']);

        $csa = $classifier->classify('nonaktif gudang sales nicky wijaya');
        $this->assertSame('CSA Program', $csa['problem_category']);
    }

    public function test_does_not_route_on_partial_word_matches(): void
    {
        $classifier = app(HelpdeskOperationalClassifier::class);

        $emailkan = $classifier->classify('mohon emailkan file ke pak budi');
        $this->assertSame(HelpdeskOperationalClassifier::UNCLASSIFIED_CATEGORY, $emailkan['problem_category']);
        $this->assertTrue($emailkan['category_uncertain'] ?? false);

        $ac = $classifier->classify('AC ruangan 2 tidak dingin sejak tadi');
        $this->assertSame(HelpdeskOperationalClassifier::UNCLASSIFIED_CATEGORY, $ac['problem_category']);
        $this->assertNotSame('Odoo Program', $ac['problem_category']);

        $kemajuan = $classifier->classify('odoo laporan kemajuan cabang');
        $this->assertSame(HelpdeskOperationalClassifier::UNSTATED_ENTITY, $kemajuan['business_entity']);
        $this->assertNotSame('CV. MAJU TECNOLOGI', $kemajuan['business_entity']);
        $this->assertNotSame('CV. CS', $kemajuan['business_entity']);

        $emailKantor = $classifier->classify('email kantor terkunci');
        $this->assertSame(HelpdeskOperationalClassifier::UNCLASSIFIED_CATEGORY, $emailKantor['problem_category']);

        $cabang = $classifier->normalizeIssue('cctv cabang tegal tidak tampil');
        $this->assertSame('Cabang tegal', $cabang['location']);

        $printer = $classifier->classify('lapor printer kasir tidak bisa mencetak sejak pagi');
        $this->assertNotSame('CCTV', $printer['problem_category']);
        $this->assertSame('Medium', $printer['priority']);
    }

    public function test_print_and_pc_language_route_to_hardware_not_holding_bucket(): void
    {
        $classifier = app(HelpdeskOperationalClassifier::class);

        $print = $classifier->classify('kasir tidak bisa print sejak pagi');
        $this->assertSame('Laptop, Komputer, Printer', $print['problem_category']);
        $this->assertFalse($print['category_uncertain'] ?? false);

        $pc = $classifier->classify('pc mati total ruang it');
        $this->assertSame('Laptop, Komputer, Printer', $pc['problem_category']);
    }

    public function test_prefix_only_message_does_not_become_the_ticket_story(): void
    {
        $classifier = app(HelpdeskOperationalClassifier::class);

        $empty = $classifier->normalizeIssue('lapor');
        $this->assertSame('', $empty['description']);
        $this->assertSame('Laporan Helpdesk', $empty['title']);
    }

    public function test_default_assignee_follows_category_names_not_ids(): void
    {
        $this->assertNull(HelpdeskOperationalClassifier::defaultAssigneeName('Odoo Program'));
        $this->assertNull(HelpdeskOperationalClassifier::defaultAssigneeName('Laptop, Komputer, Printer'));
        $this->assertNull(HelpdeskOperationalClassifier::defaultAssigneeName(HelpdeskOperationalClassifier::UNCLASSIFIED_CATEGORY));
        $this->assertSame(
            HelpdeskOperationalClassifier::OD_LEAD_USER_NAME,
            HelpdeskOperationalClassifier::defaultAssigneeName('Standard Operating Procedure (SOP)'),
        );
        $this->assertSame(
            HelpdeskOperationalClassifier::OD_LEAD_USER_NAME,
            HelpdeskOperationalClassifier::defaultAssigneeName('Struktur Organisasi '),
        );
        $this->assertSame(
            HelpdeskOperationalClassifier::OD_STAFF_USER_NAME,
            HelpdeskOperationalClassifier::defaultAssigneeName('Job Description (JD)'),
        );
    }
}
