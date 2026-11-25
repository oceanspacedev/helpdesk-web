<?php

namespace Tests\Feature;

use App\Filament\Resources\IntegrationResource;
use App\Filament\Resources\IntegrationResource\Pages\ManageIntegrations;
use App\Filament\Resources\ProblemCategoryResource;
use App\Filament\Resources\ProblemCategoryResource\Pages\CreateProblemCategory;
use App\Filament\Resources\UnitResource\Pages\ManageUnits;
use App\Models\Integration;
use App\Models\IntegrationEvent;
use App\Models\ProblemCategory;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use App\Services\Integrations\ConnectionCode;
use App\Services\Integrations\External\ExternalSystemClient;
use App\Services\Integrations\External\ExternalSystemException;
use App\Services\Integrations\IntegrationConnector;
use App\Services\Integrations\IntegrationHealthCheck;
use App\Services\Integrations\IntegrationSelfTest;
use App\Services\WhatsAppGateway;
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
use Tests\Fakes\RecordingWhatsAppGateway;
use Tests\TestCase;

/**
 * @coversNothing
 */
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

    public function test_connection_code_sets_up_integration_unit_categories_and_webhook_in_one_step(): void
    {
        $this->seedPermissions();
        $this->actingAs($this->user('Master Admin'));
        $unit = Unit::query()->create(['name' => 'BUSDEV']);
        $existing = ProblemCategory::query()->create(['unit_id' => $unit->id, 'name' => 'instruksi kerja']);
        $this->fakeExternalSystem();

        Livewire::test(ManageIntegrations::class)
            ->callAction(TestAction::make('connectSystem'), data: [
                'code' => '  '.$this->connectionCode('token-baru')."\n",
                'name' => 'Modul OD',
                'unit_id' => $unit->id,
                'category_keys' => ['IK', 'SOP', 'MEMO'],
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Terhubung');

        $integration = Integration::query()->sole();
        $this->assertSame('Modul OD', $integration->name);
        $this->assertSame('https://od.test/api/helpdesk/v1', $integration->base_url);
        $this->assertSame('token-baru', $integration->token);
        $this->assertSame(48, strlen((string) $integration->webhook_secret));
        $this->assertTrue($integration->last_check_ok);
        $this->assertSame($integration->id, $unit->fresh()->integration_id);

        // Kategori dengan nama sama dipetakan, yang baru dibuat; kategori nonaktif tidak diimpor.
        $this->assertSame('IK', $existing->fresh()->external_key);
        $this->assertSame(1, ProblemCategory::query()->whereRaw('LOWER(name) = ?', ['instruksi kerja'])->count());
        $this->assertSame('SOP', ProblemCategory::query()->where('name', 'Standard Operating Procedure')->value('external_key'));
        $this->assertSame('MEMO', ProblemCategory::query()->where('name', 'Memo Internal')->value('external_key'));
        $this->assertSame(0, ProblemCategory::query()->where('external_key', 'OLD')->count());

        $webhook = collect(Http::recorded())->map(fn (array $pair) => $pair[0])
            ->first(fn ($request): bool => $request->method() === 'PUT');
        $this->assertSame('https://od.test/api/helpdesk/v1/webhook', $webhook->url());
        $this->assertSame('Bearer token-baru', $webhook->header('Authorization')[0]);
        $this->assertSame($integration->webhookUrl(), $webhook['url']);
        $this->assertSame($integration->webhook_secret, $webhook['secret']);
    }

    public function test_reconnecting_the_same_system_keeps_the_integration_and_its_secret(): void
    {
        $this->seedPermissions();
        $this->actingAs($this->user('Master Admin'));
        $integration = $this->integration();
        $unit = Unit::query()->create(['name' => 'BUSDEV', 'integration_id' => $integration->id]);
        $this->fakeExternalSystem();

        Livewire::test(ManageIntegrations::class)
            ->callAction(TestAction::make('connectSystem'), data: [
                'code' => $this->connectionCode('token-kedua'),
                'name' => 'Modul OD',
                'unit_id' => $unit->id,
                'category_keys' => ['IK'],
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(1, Integration::query()->count());
        $this->assertSame('token-kedua', $integration->fresh()->token);
        $this->assertSame('secret', $integration->fresh()->webhook_secret);
    }

    public function test_connect_rejects_a_broken_code_or_an_unreachable_system(): void
    {
        $this->seedPermissions();
        $this->actingAs($this->user('Master Admin'));
        $unit = Unit::query()->create(['name' => 'BUSDEV']);
        Http::fake(['https://od.test/*' => Http::response(['message' => 'Token integrasi tidak valid.'], 401)]);

        Livewire::test(ManageIntegrations::class)->callAction(TestAction::make('connectSystem'), data: ['code' => 'bukan-kode', 'name' => 'X', 'unit_id' => $unit->id, 'category_keys' => ['IK']])
            ->assertHasActionErrors(['code']);
        Livewire::test(ManageIntegrations::class)->callAction(TestAction::make('connectSystem'), data: ['code' => $this->connectionCode('token-salah'), 'name' => 'X', 'unit_id' => $unit->id, 'category_keys' => ['IK']])
            ->assertHasActionErrors(['code']);

        $this->assertSame(0, Integration::query()->count());
        $this->assertNull($unit->fresh()->integration_id);
    }

    public function test_connect_still_works_when_the_system_cannot_register_webhooks(): void
    {
        $this->seedPermissions();
        $this->actingAs($this->user('Master Admin'));
        $unit = Unit::query()->create(['name' => 'BUSDEV']);
        $this->fakeExternalSystem(webhookStatus: 404);

        Livewire::test(ManageIntegrations::class)
            ->callAction(TestAction::make('connectSystem'), data: [
                'code' => $this->connectionCode('token-baru'),
                'name' => 'Sistem Lama',
                'unit_id' => $unit->id,
                'category_keys' => ['IK'],
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Terhubung, webhook perlu diatur manual');

        $this->assertSame(Integration::query()->sole()->id, $unit->fresh()->integration_id);
    }

    public function test_only_selected_active_categories_are_imported(): void
    {
        $unit = Unit::query()->create(['name' => 'BUSDEV']);
        $this->fakeExternalSystem();

        $result = app(IntegrationConnector::class)->connect(
            ConnectionCode::parse($this->connectionCode('t')),
            '',
            $unit->id,
            ['SOP', 'OLD', 'TIDAK-ADA'],
        );

        $this->assertSame('Modul', $result->integration->name, 'Nama kosong memakai nama dari kode.');
        $this->assertSame(['Standard Operating Procedure'], $result->createdCategories);
        $this->assertSame(['SOP'], ProblemCategory::query()->where('unit_id', $unit->id)->pluck('external_key')->all());
        $this->assertNull($result->webhookError);
    }

    public function test_connection_code_parsing(): void
    {
        $code = ConnectionCode::parse($this->connectionCode('abc'));
        $this->assertSame('Modul', $code->name);
        $this->assertSame('https://od.test/api/helpdesk/v1', $code->baseUrl);
        $this->assertSame('abc', $code->token);

        foreach ([
            '',
            'hd1_!!!',
            'hd1_'.base64_encode('{"v":2,"url":"https://a.test","token":"t"}'),
            'hd1_'.base64_encode('{"v":1,"url":"javascript:alert(1)","token":"t"}'),
            'hd1_'.base64_encode('{"v":1,"url":"https://a.test","token":""}'),
        ] as $invalid) {
            $this->assertFalse(ConnectionCode::isValid($invalid), $invalid);
        }
    }

    public function test_scheduled_check_alerts_admins_only_when_an_integration_goes_down_or_recovers(): void
    {
        $this->seedPermissions();
        $this->createNotificationsTable();
        $whatsApp = new RecordingWhatsAppGateway;
        $this->app->instance(WhatsAppGateway::class, $whatsApp);
        $admin = $this->user('Master Admin');
        $admin->forceFill(['phone' => '081200000009'])->save();
        $this->user('Admin Unit')->forceFill(['phone' => '081200000010'])->save();
        $integration = $this->integration();
        $integration->forceFill(['last_check_ok' => true])->save();
        Integration::query()->create(['name' => 'Nonaktif', 'base_url' => 'https://off.test/v1', 'token' => 't', 'is_active' => false]);

        $down = true;
        Http::fake(function () use (&$down) {
            return $down
                ? Http::response([], 503)
                : Http::response(['data' => [['key' => 'IK', 'name' => 'Instruksi Kerja', 'active' => true]]]);
        });
        $this->artisan('integrations:check')->assertSuccessful();
        $this->artisan('integrations:check')->assertSuccessful();

        $this->assertFalse($integration->fresh()->last_check_ok);
        $this->assertCount(1, $whatsApp->messages, 'Gangguan yang berlanjut tidak dikabarkan ulang; admin unit tidak dikabari.');
        $this->assertSame('081200000009', $whatsApp->messages[0]['phone']);
        $this->assertStringContainsString('Integrasi Modul OD terputus', $whatsApp->messages[0]['message']);
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $admin->id)->count());
        $this->assertNull(Integration::query()->where('name', 'Nonaktif')->value('last_checked_at'), 'Integrasi nonaktif tidak dites.');

        $down = false;
        $this->artisan('integrations:check')->assertSuccessful();

        $this->assertTrue($integration->fresh()->last_check_ok);
        $this->assertCount(2, $whatsApp->messages);
        $this->assertStringContainsString('tersambung kembali', $whatsApp->messages[1]['message']);
    }

    public function test_health_check_reports_new_and_missing_categories_and_sync_imports_the_new_ones(): void
    {
        $this->seedPermissions();
        $this->actingAs($this->user('Master Admin'));
        $integration = $this->integration();
        $unit = Unit::query()->create(['name' => 'BUSDEV', 'integration_id' => $integration->id]);
        ProblemCategory::query()->create(['unit_id' => $unit->id, 'name' => 'Instruksi Kerja', 'external_key' => 'IK']);
        ProblemCategory::query()->create(['unit_id' => $unit->id, 'name' => 'Kategori Lama', 'external_key' => 'OLD']);
        $deleted = ProblemCategory::query()->create(['unit_id' => $unit->id, 'name' => 'Memo Internal', 'external_key' => 'MEMO']);
        $deleted->delete();
        $this->fakeExternalSystem();

        $integration->refresh();
        $health = app(IntegrationHealthCheck::class)->run($integration);
        $this->assertTrue($health->ok);
        $this->assertSame(['Standard Operating Procedure'], $health->newCategories, 'Kategori yang dihapus admin tidak dianggap baru.');
        $this->assertSame(['Kategori Lama'], $health->missingCategories, 'OLD nonaktif di sistem tujuan.');
        $this->assertStringContainsString('1 kategori baru belum diimpor', (string) $integration->fresh()->last_check_message);

        Livewire::test(ManageIntegrations::class)
            ->callAction(TestAction::make('syncCategories')->table($integration))
            ->assertNotified('Sinkron kategori selesai');

        $this->assertSame('SOP', ProblemCategory::query()->where('name', 'Standard Operating Procedure')->value('external_key'));
        $this->assertTrue($deleted->fresh()->trashed(), 'Sinkron tidak memulihkan kategori yang dihapus admin.');
        $this->assertNotNull(ProblemCategory::query()->where('name', 'Kategori Lama')->first(), 'Sinkron tidak menghapus kategori.');
        $this->assertStringNotContainsString('belum diimpor', (string) $integration->fresh()->last_check_message);
    }

    public function test_client_survives_hostile_category_lists_and_never_follows_redirects(): void
    {
        $integration = $this->integration();
        $integration->refresh();
        Http::fake([
            'https://od.test/api/helpdesk/v1/categories' => Http::response(['data' => [
                ['key' => 'IK', 'name' => str_repeat('N', 600), 'active' => true],
                ['key' => str_repeat('K', 101), 'name' => 'Key terlalu panjang', 'active' => true],
                ['key' => 'IK', 'name' => 'Duplikat', 'active' => true],
                ['key' => null, 'name' => 'Tanpa key'],
                'bukan-objek',
            ]]),
        ]);

        $categories = ExternalSystemClient::for($integration)->categories();
        $this->assertSame(['IK'], array_column($categories, 'key'));
        $this->assertSame(255, mb_strlen($categories[0]['name']));

        $unit = Unit::query()->create(['name' => 'BUSDEV', 'integration_id' => $integration->id]);
        $result = app(IntegrationConnector::class)->syncCategories($integration);
        $this->assertCount(1, $result['created'], 'Nama panjang dipotong, tidak membuat query gagal.');
        $this->assertSame(1, ProblemCategory::query()->where('unit_id', $unit->id)->count());

        Http::fake(['https://redirect.test/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/'])]);
        $integration->update(['base_url' => 'https://redirect.test/v1']);
        try {
            ExternalSystemClient::for($integration->fresh())->categories();
            $this->fail('Redirect harus dianggap gagal.');
        } catch (ExternalSystemException $exception) {
            $this->assertStringContainsString('HTTP 302', $exception->getMessage());
        }
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '169.254.169.254'));
    }

    public function test_connection_code_and_manual_form_reject_metadata_and_credential_urls(): void
    {
        $this->seedPermissions();
        $this->actingAs($this->user('Master Admin'));

        foreach (['http://169.254.169.254/latest', 'http://user:pass@od.test/api', 'http://[fe80::1]/api'] as $url) {
            $json = json_encode(['v' => 1, 'name' => 'X', 'url' => $url, 'token' => 't'], JSON_UNESCAPED_SLASHES);
            $this->assertFalse(ConnectionCode::isValid('hd1_'.rtrim(strtr(base64_encode($json), '+/', '-_'), '=')), $url);
        }

        Livewire::test(ManageIntegrations::class)
            ->callAction(TestAction::make('create'), data: [
                'name' => 'Metadata', 'base_url' => 'http://169.254.169.254/latest', 'token' => 't', 'webhook_secret' => 's', 'timeout_seconds' => 10, 'is_active' => true,
            ])
            ->assertHasActionErrors(['base_url']);
        $this->assertSame(0, Integration::query()->count());
    }

    public function test_connection_test_proves_forms_and_the_webhook_round_trip_without_creating_a_ticket(): void
    {
        $this->seedPermissions();
        $this->actingAs($this->user('Master Admin'));
        $integration = $this->integration();
        $integration->refresh();
        $unit = Unit::query()->create(['name' => 'BUSDEV', 'integration_id' => $integration->id]);
        ProblemCategory::query()->create(['unit_id' => $unit->id, 'name' => 'Instruksi Kerja', 'external_key' => 'IK']);
        ProblemCategory::query()->create(['unit_id' => $unit->id, 'name' => 'Memo', 'external_key' => 'MEMO']);

        $mode = 'arrives';
        Http::fake(function ($request) use (&$mode, $integration) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                str_ends_with($path, '/categories') => Http::response(['data' => [['key' => 'IK', 'name' => 'Instruksi Kerja', 'active' => true], ['key' => 'MEMO', 'name' => 'Memo', 'active' => true]]]),
                str_ends_with($path, '/forms/IK') => Http::response(['data' => ['key' => 'IK', 'title' => 'IK', 'fields' => [['key' => 'judul', 'type' => 'text', 'label' => 'Judul', 'required' => true]]]]),
                str_ends_with($path, '/forms/MEMO') => $mode === 'broken-form'
                    ? Http::response(['message' => 'Not Found'], 404)
                    : Http::response(['data' => ['key' => 'MEMO', 'title' => 'Memo', 'fields' => [['key' => 'isi', 'type' => 'textarea', 'label' => 'Isi', 'required' => true]]]]),
                str_ends_with($path, '/webhook/test') => (function () use ($mode, $integration) {
                    if ($mode === 'arrives') {
                        // Sistem tujuan sungguhan mengirim ping ke /api/integrations/{id}/events.
                        IntegrationEvent::query()->firstOrCreate(['integration_id' => $integration->id, 'event_id' => 'ping-1'], ['received_at' => now()]);
                    }

                    return $mode === 'rejected'
                        ? Http::response(['data' => ['delivered' => false, 'event_id' => 'ping-2', 'error' => 'Helpdesk menolak tanda tangan (rahasia webhook tidak cocok).']])
                        : Http::response(['data' => ['delivered' => true, 'event_id' => $mode === 'arrives' ? 'ping-1' : 'ping-nyasar', 'error' => null]]);
                })(),
                default => Http::response([], 500),
            };
        });

        $test = app(IntegrationSelfTest::class);
        $result = $test->run($integration);
        $this->assertTrue($result->passed(), $result->summary());
        $this->assertStringContainsString('✓ Form kategori: 2 form dimuat dan valid.', $result->summary());
        $this->assertStringContainsString('✓ Webhook: Ping dari sistem tujuan tiba', $result->summary());
        $this->assertSame(0, Ticket::query()->count(), 'Tes tidak membuat tiket.');

        Livewire::test(ManageIntegrations::class)
            ->callAction(TestAction::make('testConnection')->table($integration))
            ->assertNotified('Koneksi berhasil');

        $mode = 'elsewhere';
        $result = $test->run($integration);
        $this->assertFalse($result->passed());
        $this->assertStringContainsString('alamat lain', $result->summary());

        $mode = 'rejected';
        $this->assertStringContainsString('✗ Webhook: Helpdesk menolak tanda tangan', $test->run($integration)->summary());

        $mode = 'broken-form';
        $result = $test->run($integration);
        $this->assertStringContainsString('✗ Form kategori: Bermasalah: Memo (tidak ada di sistem tujuan)', $result->summary());
        Livewire::test(ManageIntegrations::class)
            ->callAction(TestAction::make('testConnection')->table($integration))
            ->assertNotified('Terhubung, tetapi ada yang bermasalah');
    }

    private function createNotificationsTable(): void
    {
        if (Schema::hasTable('notifications')) {
            return;
        }

        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    private function connectionCode(string $token): string
    {
        $json = json_encode(['v' => 1, 'name' => 'Modul', 'url' => 'https://od.test/api/helpdesk/v1/', 'token' => $token], JSON_UNESCAPED_SLASHES);

        return 'hd1_'.rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    private function fakeExternalSystem(int $webhookStatus = 200): void
    {
        Http::fake([
            'https://od.test/api/helpdesk/v1/categories' => Http::response(['data' => [
                ['key' => 'IK', 'name' => 'Instruksi Kerja', 'active' => true, 'description' => 'Petunjuk kerja.'],
                ['key' => 'SOP', 'name' => 'Standard Operating Procedure', 'active' => true],
                ['key' => 'MEMO', 'name' => 'Memo Internal', 'active' => true],
                ['key' => 'OLD', 'name' => 'Lama', 'active' => false],
            ]]),
            'https://od.test/api/helpdesk/v1/forms/*' => Http::response(['data' => ['key' => 'X', 'title' => 'Form', 'fields' => [['key' => 'judul', 'type' => 'text', 'label' => 'Judul', 'required' => true]]]]),
            // Sistem uji ini belum mendukung tes webhook (langkahnya dilewati).
            'https://od.test/api/helpdesk/v1/webhook/test' => Http::response(['message' => 'Not Found'], 404),
            'https://od.test/api/helpdesk/v1/webhook' => Http::response(['data' => ['registered' => true]], $webhookStatus),
        ]);
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
