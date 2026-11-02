<?php

namespace Tests\Feature;

use App\Models\BusinessEntity;
use App\Models\Priority;
use App\Models\ProblemCategory;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\Unit;
use App\Models\User;
use App\Services\Integrations\HelpdeskFormOptionsService;
use App\Support\HelpdeskWhatsAppMessage;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesHelpdeskIntegrationSchema;
use Tests\TestCase;

class TalentaEmployeeSyncTest extends TestCase
{
    use CreatesHelpdeskIntegrationSchema;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['user_entities', 'role_has_permissions', 'model_has_permissions', 'permissions'] as $table) {
            Schema::dropIfExists($table);
        }

        $this->createTestSchema();
        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name')->default('web');
            $table->timestamps();
        });
        Schema::create('model_has_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
        });
        Schema::create('role_has_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
        });
        Schema::create('user_entities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->morphs('entity');
            $table->timestamps();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['Super Admin', 'Master Admin', 'Staff Unit', 'User'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }

        Notification::fake();
        config()->set('services.talenta.division_aliases', ['IT SUPPORT' => 'IT']);
        $this->file = tempnam(sys_get_temp_dir(), 'talenta-');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);

        parent::tearDown();
    }

    public function test_preview_reports_changes_without_saving_anything(): void
    {
        $this->writeEmployees([
            $this->employee('2024.01.01.01', 'BUDI', 'SANTOSO', '081200000001', 'CV Top Selular', 'HUMAN CAPITAL', 'DRIVER'),
        ]);

        $this->artisan('helpdesk:sync-employees', ['file' => $this->file])
            ->expectsOutputToContain('PREVIEW')
            ->expectsOutputToContain('CV Top Selular')
            ->expectsOutputToContain('HUMAN CAPITAL')
            ->assertSuccessful();

        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Unit::query()->count());
        $this->assertSame(0, BusinessEntity::query()->count());
    }

    public function test_apply_maps_entity_division_and_job_like_a_letterhead(): void
    {
        $it = Unit::create(['name' => 'IT']);
        $busdev = Unit::create(['name' => 'BUSDEV']);
        $entity = BusinessEntity::create(['name' => 'PT Media Selular Indonesia']);

        // Akun lama dari form publik: hanya nomor HP, plus keanggotaan BUSDEV manual.
        $existing = User::create([
            'name' => 'Pelapor WhatsApp 0002',
            'phone' => '081200000002',
            'is_active' => true,
        ]);
        $existing->units()->attach($busdev->id);
        $admin = User::create(['name' => 'Admin', 'identity' => '2010.01.01.01', 'phone' => '081200000009', 'is_active' => true]);
        $admin->assignRole('Super Admin');
        $leaver = User::create(['name' => 'Sudah Keluar', 'identity' => '2015.01.01.01', 'phone' => '081200000008', 'is_active' => true]);
        $nonTalenta = User::create(['name' => 'Vendor', 'phone' => '081200000007', 'is_active' => true]);

        $this->writeEmployees([
            $this->employee('2024.01.01.02', 'SITI', 'AMINAH', '+6281200000002', 'PT. Media  Selular Indonesia', 'IT SUPPORT', 'STAFF IT', 'STAFF'),
            $this->employee('2024.01.01.03', 'AGUS', '', '081200000003', 'CV Top Selular', 'HUMAN CAPITAL', 'DRIVER'),
            $this->employee('2024.01.01.04', 'TANPA', 'NOMOR', null, 'CV Top Selular', 'HUMAN CAPITAL', 'STAFF HR'),
            $this->employee('2024.01.01.05', 'KEMBAR', 'SATU', '081200000005', 'CV Top Selular', 'FINANCE ACCOUNTING', 'STAFF'),
            $this->employee('2024.01.01.06', 'KEMBAR', 'DUA', '081200000005', 'CV Top Selular', 'FINANCE ACCOUNTING', 'STAFF'),
        ]);

        $this->artisan('helpdesk:sync-employees', ['file' => $this->file, '--apply' => true])
            ->expectsOutputToContain('Sinkron disimpan.')
            ->assertSuccessful();

        // Nama badan usaha dicocokkan tanpa peduli titik/spasi; IT SUPPORT memakai alias IT.
        $this->assertSame(2, BusinessEntity::query()->count());
        $topSelular = BusinessEntity::query()->where('name', 'CV Top Selular')->sole();
        $humanCapital = Unit::query()->where('name', 'HUMAN CAPITAL')->sole();
        $this->assertFalse(Unit::query()->where('name', 'IT SUPPORT')->exists());

        $existing->refresh();
        $this->assertSame('SITI AMINAH', $existing->name);
        $this->assertSame('2024.01.01.02', $existing->identity);
        $this->assertSame($entity->id, $existing->business_entity_id);
        $this->assertSame($it->id, $existing->unit_id);
        $this->assertSame('STAFF IT', $existing->job_title);
        $this->assertSame('STAFF', $existing->job_level);
        $this->assertEqualsCanonicalizing([$it->id, $busdev->id], $existing->assignedUnitIds());
        $this->assertTrue($existing->hasRole('User'));

        $driver = User::query()->where('identity', '2024.01.01.03')->sole();
        $this->assertSame('AGUS', $driver->name);
        $this->assertSame('6281200000003', $driver->phone_normalized);
        $this->assertSame($topSelular->id, $driver->business_entity_id);
        $this->assertSame([$humanCapital->id], $driver->assignedUnitIds());
        $this->assertTrue($driver->hasRole('User'));

        $this->assertFalse(User::query()->where('identity', '2024.01.01.04')->exists());
        $this->assertFalse(User::query()->whereIn('identity', ['2024.01.01.05', '2024.01.01.06'])->exists());

        $this->assertFalse($leaver->fresh()->is_active);
        $this->assertTrue($admin->fresh()->is_active);
        $this->assertTrue($nonTalenta->fresh()->is_active);
    }

    public function test_resync_moves_talenta_division_but_keeps_manual_memberships(): void
    {
        $busdev = Unit::create(['name' => 'BUSDEV']);
        $this->writeEmployees([
            $this->employee('2024.01.01.10', 'RINA', '', '081200000010', 'CV Top Selular', 'FINANCE ACCOUNTING', 'STAFF'),
        ]);
        $this->artisan('helpdesk:sync-employees', ['file' => $this->file, '--apply' => true])->assertSuccessful();

        $user = User::query()->where('identity', '2024.01.01.10')->sole();
        $user->units()->attach($busdev->id);
        $finance = Unit::query()->where('name', 'FINANCE ACCOUNTING')->sole();

        $this->writeEmployees([
            $this->employee('2024.01.01.10', 'RINA', '', '081200000010', 'CV Top Selular', 'HUMAN CAPITAL', 'STAFF HR'),
        ]);
        $this->artisan('helpdesk:sync-employees', ['file' => $this->file, '--apply' => true])->assertSuccessful();

        $user->refresh();
        $humanCapital = Unit::query()->where('name', 'HUMAN CAPITAL')->sole();
        $this->assertSame($humanCapital->id, $user->unit_id);
        $this->assertSame('STAFF HR', $user->job_title);
        $this->assertEqualsCanonicalizing([$humanCapital->id, $busdev->id], $user->assignedUnitIds());
        $this->assertNotContains($finance->id, $user->assignedUnitIds());
    }

    public function test_ticket_keeps_sender_profile_and_only_divisions_with_categories_are_destinations(): void
    {
        $it = Unit::create(['name' => 'IT']);
        $category = ProblemCategory::create(['unit_id' => $it->id, 'name' => 'Printer']);
        $priority = Priority::create(['id' => Priority::MEDIUM, 'name' => 'Medium']);
        $this->writeEmployees([
            $this->employee('2024.01.01.20', 'ANDI', 'GUDANG', '081200000020', 'PT Media Selular Indonesia', 'SUPPLY CHAIN', 'STAFF WAREHOUSE'),
        ]);
        $this->artisan('helpdesk:sync-employees', ['file' => $this->file, '--apply' => true])->assertSuccessful();

        $reporter = User::query()->where('identity', '2024.01.01.20')->sole();
        $supplyChain = Unit::query()->where('name', 'SUPPLY CHAIN')->sole();
        $entity = BusinessEntity::query()->where('name', 'PT Media Selular Indonesia')->sole();
        $otherEntity = BusinessEntity::create(['name' => 'Belum disebutkan']);

        $ticket = Ticket::create([
            'priority_id' => $priority->id,
            'unit_id' => $it->id,
            'owner_id' => $reporter->id,
            'problem_category_id' => $category->id,
            'title' => 'Printer gudang macet',
            'description' => 'Printer label gudang tidak menarik kertas.',
            'ticket_statuses_id' => TicketStatus::OPEN,
            'business_entities_id' => $otherEntity->id,
        ]);

        $this->assertSame($supplyChain->id, $ticket->reporter_unit_id);
        $this->assertSame('STAFF WAREHOUSE', $ticket->reporter_job_title);
        // Kontrak di PT Media, tetapi laporannya untuk badan usaha lain.
        $this->assertSame($entity->id, $ticket->reporter_business_entity_id);
        $this->assertSame($otherEntity->id, $ticket->business_entities_id);
        $this->assertStringContainsString(
            '👤 ANDI GUDANG · STAFF WAREHOUSE · SUPPLY CHAIN · PT Media Selular Indonesia',
            HelpdeskWhatsAppMessage::forStaff($ticket->fresh()),
        );

        // Tanpa pilihan, badan usaha kontrak menjadi badan usaha yang dilaporkan.
        $sameEntity = Ticket::create([
            'priority_id' => $priority->id,
            'unit_id' => $it->id,
            'owner_id' => $reporter->id,
            'problem_category_id' => $category->id,
            'title' => 'Scanner gudang mati',
            'description' => 'Scanner barcode gudang tidak menyala.',
            'ticket_statuses_id' => TicketStatus::OPEN,
        ]);
        $this->assertSame($entity->id, $sameEntity->business_entities_id);
        $this->assertStringNotContainsString(
            'SUPPLY CHAIN · PT Media Selular Indonesia',
            HelpdeskWhatsAppMessage::forStaff($sameEntity->fresh()),
        );

        // Pindah divisi tidak mengubah "kop surat" tiket lama.
        $reporter->update(['unit_id' => $it->id, 'job_title' => 'STAFF IT']);
        $this->assertSame($supplyChain->id, $ticket->fresh()->reporter_unit_id);

        $destinations = collect(app(HelpdeskFormOptionsService::class)->formOptions()['units'])->pluck('name');
        $this->assertSame(['IT'], $destinations->all());
    }

    /**
     * @param  list<array<string, mixed>>  $employees
     */
    private function writeEmployees(array $employees): void
    {
        file_put_contents($this->file, json_encode([
            'message' => 'success',
            'status' => 200,
            'data' => ['data' => $employees],
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function employee(
        string $nik,
        string $firstName,
        string $lastName,
        ?string $phone,
        string $branch,
        string $organization,
        string $job,
        string $title = 'STAFF',
    ): array {
        return [
            'branch' => $branch,
            'email' => null,
            'mobile_phone' => $phone,
            'phone' => null,
            'id' => (string) crc32($nik),
            'id_employee' => $nik,
            'job' => $job,
            'organization' => $organization,
            'title' => $title,
            'first_name' => $firstName,
            'last_name' => $lastName,
        ];
    }
}
