<?php

namespace Tests\Feature;

use App\Filament\Resources\IntegrationResource;
use App\Filament\Resources\IntegrationResource\Pages\ManageIntegrations;
use App\Filament\Resources\ProblemCategoryResource;
use App\Filament\Resources\ProblemCategoryResource\Pages\CreateProblemCategory;
use App\Filament\Resources\UnitResource\Pages\ManageUnits;
use App\Models\Integration;
use App\Models\ProblemCategory;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesHelpdeskIntegrationSchema;
use Tests\TestCase;

class IntegrationResourceTest extends TestCase
{
    use CreatesHelpdeskIntegrationSchema;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['user_entities', 'role_has_permissions', 'model_has_permissions', 'permissions'] as $table) {
            Schema::dropIfExists($table);
        }

        $this->createTestSchema();
        $this->createPermissionSchema();
        Http::preventStrayRequests();
    }

    public function test_permission_migration_grants_integration_access_to_global_admins_only(): void
    {
        foreach (['Super Admin', 'Master Admin', 'Admin Unit', 'User'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }

        $migration = require database_path('migrations/2026_11_25_000003_grant_integration_permissions.php');
        $migration->up();
        $migration->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertSame(5, Permission::query()->where('name', 'like', '%:Integration')->count());
        $this->assertTrue(Role::findByName('Master Admin')->hasPermissionTo('ViewAny:Integration'));
        $this->assertTrue(Role::findByName('Super Admin')->hasPermissionTo('Update:Integration'));
        $this->assertFalse(Role::findByName('Admin Unit')->hasPermissionTo('ViewAny:Integration'));
        $this->assertFalse(Role::findByName('User')->hasPermissionTo('ViewAny:Integration'));
    }

    public function test_only_global_admins_can_manage_integrations(): void
    {
        $this->seedPermissions();
        $superAdmin = $this->user('Super Admin');
        $masterAdmin = $this->user('Master Admin');
        $adminUnit = $this->user('Admin Unit');
        $reporter = $this->user('User');
        $integration = $this->integration();

        $this->assertTrue(Gate::forUser($superAdmin)->allows('viewAny', Integration::class));
        $this->assertTrue(Gate::forUser($masterAdmin)->allows('viewAny', Integration::class));
        $this->assertTrue(Gate::forUser($masterAdmin)->allows('update', $integration));
        $this->assertTrue(Gate::forUser($masterAdmin)->allows('delete', $integration));
        $this->assertFalse(Gate::forUser($adminUnit)->allows('viewAny', Integration::class));
        $this->assertFalse(Gate::forUser($adminUnit)->allows('update', $integration));
        $this->assertFalse(Gate::forUser($reporter)->allows('viewAny', Integration::class));

        // Permission saja tidak cukup tanpa role admin global.
        $adminUnit->givePermissionTo('ViewAny:Integration');
        $this->assertFalse(Gate::forUser($adminUnit->fresh())->allows('viewAny', Integration::class));

        Unit::query()->create(['name' => 'BUSDEV', 'integration_id' => $integration->id]);
        $this->assertFalse(
            Gate::forUser($masterAdmin)->allows('delete', $integration->fresh()),
            'Integrasi yang masih dipakai unit tidak boleh dihapus.',
        );

        $this->actingAs($adminUnit);
        Livewire::test(ManageIntegrations::class)->assertForbidden();
    }

    public function test_secrets_are_write_only_and_connection_test_is_recorded(): void
    {
        $this->seedPermissions();
        $this->actingAs($this->user('Master Admin'));

        $page = Livewire::test(ManageIntegrations::class)
            ->callAction(TestAction::make('create'), data: [
                'name' => 'Modul OD',
                'base_url' => 'https://od.test/api/helpdesk/v1',
                'token' => 'token-awal',
                'webhook_secret' => 'rahasia-awal',
                'timeout_seconds' => 10,
                'is_active' => true,
            ])
            ->assertHasNoActionErrors();

        $integration = Integration::query()->firstOrFail();
        $this->assertSame('token-awal', $integration->token);
        $this->assertNotSame('token-awal', DB::table('integrations')->value('token'), 'Token harus terenkripsi di database.');
        $this->assertArrayNotHasKey('token', $integration->toArray());
        $this->assertArrayNotHasKey('webhook_secret', $integration->toArray());

        $page->mountAction(TestAction::make('edit')->table($integration))
            ->assertSchemaStateSet(['token' => null, 'webhook_secret' => null, 'name' => 'Modul OD'], 'mountedActionSchema0')
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $integration->refresh();
        $this->assertSame('token-awal', $integration->token, 'Field kosong saat ubah mempertahankan token lama.');
        $this->assertSame('rahasia-awal', $integration->webhook_secret);

        Http::fake([
            'https://od.test/api/helpdesk/v1/categories' => Http::response(['data' => [['key' => 'IK', 'name' => 'Instruksi Kerja', 'active' => true]]]),
        ]);
        $page->callAction(TestAction::make('testConnection')->table($integration));

        $integration->refresh();
        $this->assertTrue($integration->last_check_ok);
        $this->assertNotNull($integration->last_checked_at);
        $this->assertStringContainsString('1 kategori', (string) $integration->last_check_message);

        $integration->update(['base_url' => 'https://down.test/api/helpdesk/v1']);
        Http::fake(['https://down.test/*' => Http::response([], 500)]);
        $this->assertFalse(IntegrationResource::testConnection($integration));
        $this->assertFalse($integration->fresh()->last_check_ok);
    }

    public function test_category_form_maps_external_key_from_the_integration(): void
    {
        $this->seedPermissions();
        $this->actingAs($this->user('Master Admin'));
        $integration = $this->integration();
        Livewire::test(ManageUnits::class)
            ->callAction(TestAction::make('create'), data: ['name' => 'BUSDEV', 'integration_id' => $integration->id])
            ->assertHasNoActionErrors();
        $external = Unit::query()->where('name', 'BUSDEV')->firstOrFail();
        $this->assertTrue($external->isExternal());
        $internal = Unit::query()->create(['name' => 'IT']);

        Http::fake([
            'https://od.test/api/helpdesk/v1/categories' => Http::response(['data' => [['key' => 'IK', 'name' => 'Instruksi Kerja', 'active' => true]]]),
        ]);

        Livewire::test(CreateProblemCategory::class)
            ->fillForm(['unit_id' => $external->id, 'name' => 'Instruksi Kerja'])
            ->call('create')
            ->assertHasFormErrors(['external_key' => 'required']);

        Livewire::test(CreateProblemCategory::class)
            ->fillForm(['unit_id' => $external->id, 'name' => 'Instruksi Kerja', 'external_key' => 'IK'])
            ->call('create')
            ->assertHasNoFormErrors();

        Livewire::test(CreateProblemCategory::class)
            ->fillForm(['unit_id' => $internal->id, 'name' => 'Jaringan'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('IK', ProblemCategory::query()->where('name', 'Instruksi Kerja')->value('external_key'));
        $this->assertNull(ProblemCategory::query()->where('name', 'Jaringan')->value('external_key'));
        $this->assertSame(
            ['IK' => 'IK — Instruksi Kerja', 'OLD' => 'OLD (tidak ditemukan di sistem eksternal)'],
            ProblemCategoryResource::externalCategoryOptions(ProblemCategoryResource::externalCategories($external->id) ?? [], 'OLD'),
        );

        // Sistem eksternal tidak bisa dihubungi: kode diisi manual.
        $down = Integration::query()->create(['name' => 'Down', 'base_url' => 'https://down.test/v1', 'token' => 't']);
        $downUnit = Unit::query()->create(['name' => 'LEGAL', 'integration_id' => $down->id]);
        Http::fake(['https://down.test/*' => Http::response([], 503)]);

        $this->assertNull(ProblemCategoryResource::externalCategories($downUnit->id));
        Livewire::test(CreateProblemCategory::class)
            ->fillForm(['unit_id' => $downUnit->id, 'name' => 'Kontrak', 'external_key' => 'KONTRAK'])
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertSame('KONTRAK', ProblemCategory::query()->where('name', 'Kontrak')->value('external_key'));
    }

    private function integration(): Integration
    {
        return Integration::query()->create([
            'name' => 'Modul OD',
            'base_url' => 'https://od.test/api/helpdesk/v1',
            'token' => 'token',
            'webhook_secret' => 'secret',
        ]);
    }

    private function user(string $role): User
    {
        $user = User::query()->create([
            'name' => $role.' User',
            'email' => strtolower(str_replace(' ', '.', $role)).'@example.test',
            'email_verified_at' => now(),
            'password' => 'secret',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function seedPermissions(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['Integration', 'ProblemCategory', 'Unit'] as $model) {
            foreach (['ViewAny', 'View', 'Create', 'Update', 'Delete'] as $ability) {
                Permission::create(['name' => $ability.':'.$model, 'guard_name' => 'web']);
            }
        }

        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function createPermissionSchema(): void
    {
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
    }
}
