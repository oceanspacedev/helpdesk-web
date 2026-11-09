<?php

namespace Tests\Feature;

use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\BusinessEntity;
use App\Models\Unit;
use App\Models\User;
use App\Services\EmployeeService;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesHelpdeskIntegrationSchema;
use Tests\TestCase;

class UserResourceTalentaTest extends TestCase
{
    use CreatesHelpdeskIntegrationSchema;

    private BusinessEntity $media;

    private Unit $it;

    private string $employeeFile;

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
            $table->unique(['name', 'guard_name']);
        });
        Schema::create('model_has_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->primary(['permission_id', 'model_id', 'model_type']);
        });
        Schema::create('role_has_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
            $table->primary(['permission_id', 'role_id']);
        });
        Schema::create('user_entities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->morphs('entity');
            $table->timestamps();
            $table->unique(['user_id', 'entity_id', 'entity_type']);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['ViewAny:User', 'View:User', 'Create:User', 'Update:User', 'Delete:User'] as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'web']);
        }
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->employeeFile = sys_get_temp_dir().'/talenta-test-'.uniqid('', true).'/talenta-list-employee.json';
        config()->set('services.talenta.employee_file', $this->employeeFile);

        Notification::fake();
        $this->media = BusinessEntity::create(['name' => 'PT Media Selular Indonesia']);
        $this->it = Unit::create(['name' => 'IT']);
    }

    public function test_talenta_contract_fields_are_locked_while_assignments_stay_editable(): void
    {
        $hr = Unit::create(['name' => 'HR']);
        $other = BusinessEntity::create(['name' => 'CV Top Selular']);
        $employee = User::create([
            'name' => 'SITI AMINAH',
            'identity' => '2024.01.01.30',
            'business_entity_id' => $this->media->id,
            'unit_id' => $this->it->id,
            'job_title' => 'STAFF IT',
            'phone' => '6281200000030',
            'is_active' => true,
        ]);
        $employee->ensurePrimaryUnitMembership();

        $this->actingAs($this->admin('Master Admin'));

        Livewire::test(EditUser::class, ['record' => $employee->getRouteKey()])
            ->assertFormFieldDisabled('name')
            ->assertFormFieldDisabled('business_entity_id')
            ->assertFormFieldDisabled('unit_id')
            ->assertFormFieldDisabled('job_title')
            ->assertFormFieldEnabled('units')
            ->assertFormFieldEnabled('phone')
            // State yang dikunci tidak ikut tersimpan walaupun diubah dari klien.
            ->fillForm([
                'name' => 'NAMA LAIN',
                'business_entity_id' => $other->id,
                'unit_id' => $hr->id,
                'units' => [$this->it->id, $hr->id],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $employee->refresh();
        $this->assertSame('SITI AMINAH', $employee->name);
        $this->assertSame($this->media->id, $employee->business_entity_id);
        $this->assertSame($this->it->id, $employee->unit_id);
        $this->assertEqualsCanonicalizing([$this->it->id, $hr->id], $employee->assignedUnitIds());
    }

    public function test_manual_user_contract_fields_stay_editable(): void
    {
        $manual = User::create([
            'name' => 'Vendor Printer',
            'phone' => '6281200000031',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin('Master Admin'));

        Livewire::test(EditUser::class, ['record' => $manual->getRouteKey()])
            ->assertFormFieldEnabled('name')
            ->assertFormFieldEnabled('business_entity_id')
            ->assertFormFieldEnabled('unit_id')
            ->fillForm(['name' => 'Vendor Printer Baru', 'business_entity_id' => $this->media->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Vendor Printer Baru', $manual->fresh()->name);
        $this->assertSame($this->media->id, $manual->fresh()->business_entity_id);
    }

    public function test_global_admin_previews_then_applies_the_talenta_import(): void
    {
        $this->actingAs($this->admin('Master Admin'));
        $json = $this->talentaJson([
            $this->employee('2024.01.01.40', 'ANDI', '081200000040', 'PT Media Selular Indonesia', 'IT', 'STAFF IT'),
        ]);

        Livewire::test(ListUsers::class)
            ->callAction('importTalenta', data: [
                'file' => UploadedFile::fake()->createWithContent('talenta.json', $json),
                'apply' => false,
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Preview sinkron Talenta — belum ada yang disimpan.');

        $this->assertFalse(User::query()->where('identity', '2024.01.01.40')->exists());
        $this->assertFileDoesNotExist($this->employeeFile);

        Livewire::test(ListUsers::class)
            ->callAction('importTalenta', data: [
                'file' => UploadedFile::fake()->createWithContent('talenta.json', $json),
                'apply' => true,
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Sinkron Talenta disimpan.');

        $andi = User::query()->where('identity', '2024.01.01.40')->sole();
        $this->assertSame($this->media->id, $andi->business_entity_id);
        $this->assertSame($this->it->id, $andi->unit_id);
        $this->assertSame('STAFF IT', $andi->job_title);

        // File yang sama menggantikan direktori karyawan untuk login OTP dan auto-registrasi.
        $this->assertStringEqualsFile($this->employeeFile, $json);
        $this->assertSame([], glob($this->employeeFile.'.*.tmp'));
        $this->assertCount(1, app(EmployeeService::class)->findAllByPhone('081200000040'));
    }

    public function test_applying_a_new_talenta_export_replaces_the_previous_employee_file(): void
    {
        File::ensureDirectoryExists(dirname($this->employeeFile));
        file_put_contents($this->employeeFile, $this->talentaJson([
            $this->employee('2024.01.01.41', 'LAMA', '081200000041', 'PT Media Selular Indonesia', 'IT', 'STAFF IT'),
        ]));
        $this->actingAs($this->admin('Master Admin'));
        $json = $this->talentaJson([
            $this->employee('2024.01.01.42', 'BARU', '081200000042', 'PT Media Selular Indonesia', 'IT', 'STAFF IT'),
        ]);

        Livewire::test(ListUsers::class)
            ->callAction('importTalenta', data: [
                'file' => UploadedFile::fake()->createWithContent('talenta.json', $json),
                'apply' => true,
            ])
            ->assertHasNoActionErrors();

        $this->assertStringEqualsFile($this->employeeFile, $json);
        $directory = app(EmployeeService::class);
        $this->assertSame([], $directory->findAllByPhone('081200000041'));
        $this->assertCount(1, $directory->findAllByPhone('081200000042'));
    }

    public function test_invalid_talenta_file_is_rejected_without_changes(): void
    {
        $this->actingAs($this->admin('Master Admin'));

        Livewire::test(ListUsers::class)
            ->callAction('importTalenta', data: [
                'file' => UploadedFile::fake()->createWithContent('talenta.json', '{"data": []}'),
                'apply' => true,
            ])
            ->assertNotified('File JSON Talenta tidak dapat dibaca.');

        $this->assertSame(1, User::query()->count());
        $this->assertFileDoesNotExist($this->employeeFile);
    }

    public function test_unit_admin_cannot_import_talenta(): void
    {
        $unitAdmin = $this->admin('Admin Unit');
        $unitAdmin->forceFill(['unit_id' => $this->it->id])->save();
        $unitAdmin->ensurePrimaryUnitMembership();
        $this->actingAs($unitAdmin);

        Livewire::test(ListUsers::class)->assertActionHidden('importTalenta');
    }

    protected function tearDown(): void
    {
        if (isset($this->employeeFile)) {
            File::deleteDirectory(dirname($this->employeeFile));
        }

        parent::tearDown();
    }

    private function admin(string $role): User
    {
        $user = User::create([
            'name' => $role.' Helpdesk',
            'email' => strtolower(str_replace(' ', '.', $role)).'@example.test',
            'email_verified_at' => now(),
            'email_verified_via' => 'admin',
            'password' => 'secret',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    /**
     * @param  list<array<string, mixed>>  $employees
     */
    private function talentaJson(array $employees): string
    {
        return (string) json_encode(['data' => ['data' => $employees]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function employee(string $nik, string $name, string $phone, string $branch, string $organization, string $job): array
    {
        return [
            'id_employee' => $nik,
            'first_name' => $name,
            'last_name' => '',
            'email' => null,
            'mobile_phone' => $phone,
            'phone' => null,
            'branch' => $branch,
            'organization' => $organization,
            'job' => $job,
            'title' => 'STAFF',
        ];
    }
}
