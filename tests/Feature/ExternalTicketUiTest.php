<?php

namespace Tests\Feature;

use App\Filament\Resources\TicketResource\ExternalTicketForm;
use App\Filament\Resources\TicketResource\Pages\CreateTicket;
use App\Filament\Resources\TicketResource\Pages\EditTicket;
use App\Filament\Resources\TicketResource\Pages\ListTickets;
use App\Filament\Resources\TicketResource\Pages\ViewTicket;
use App\Models\BusinessEntity;
use App\Models\Integration;
use App\Models\Priority;
use App\Models\ProblemCategory;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\Unit;
use App\Models\User;
use App\Services\Integrations\External\ExternalFormSchema;
use App\Services\Integrations\External\ExternalTicketService;
use App\Services\Integrations\External\ExternalUnavailableException;
use App\Services\Integrations\HelpdeskReporterIdentityService;
use App\Services\Integrations\PublicReporterIdentityService;
use App\Services\WhatsAppGateway;
use Database\Seeders\RoleSeeder;
use Filament\Forms\Components\Repeater;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesHelpdeskIntegrationSchema;
use Tests\Fakes\RecordingWhatsAppGateway;
use Tests\TestCase;

class ExternalTicketUiTest extends TestCase
{
    use CreatesHelpdeskIntegrationSchema;

    private const BASE = 'https://od.test/api/helpdesk/v1';

    private const TOKEN = 'rahasia-token-od';

    private const DEVICE_SESSION_KEY = 'helpdesk.public_reporter.device_token';

    private const SUBMISSION_SESSION_KEY = 'helpdesk.public_ticket.submission_token';

    private Integration $integration;

    private Unit $busdev;

    private Unit $it;

    private ProblemCategory $category;

    private ProblemCategory $internalCategory;

    private BusinessEntity $entity;

    private Priority $priority;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['user_entities', 'role_has_permissions', 'model_has_permissions', 'permissions'] as $table) {
            Schema::dropIfExists($table);
        }

        $this->createTestSchema();
        $this->createAuthorizationSchema();
        $this->seedTicketPermissionsAndRoles();
        Cache::flush();
        Notification::fake();
        Http::preventStrayRequests();
        $this->app->instance(WhatsAppGateway::class, new RecordingWhatsAppGateway);
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.41']);

        $this->priority = Priority::query()->create(['id' => Priority::MEDIUM, 'name' => 'Medium']);
        $this->entity = BusinessEntity::query()->create(['name' => 'Complete Selular']);
        $this->integration = Integration::query()->create([
            'name' => 'Modul OD',
            'base_url' => self::BASE,
            'token' => self::TOKEN,
            'webhook_secret' => 'rahasia-webhook-od',
            'is_active' => true,
            'timeout_seconds' => 10,
        ]);
        $this->busdev = Unit::query()->create(['name' => 'BUSDEV', 'integration_id' => $this->integration->id]);
        $this->it = Unit::query()->create(['name' => 'IT']);
        $this->category = ProblemCategory::query()->create([
            'unit_id' => $this->busdev->id,
            'name' => 'Instruksi Kerja (IK)',
            'external_key' => 'IK',
        ]);
        $this->internalCategory = ProblemCategory::query()->create([
            'unit_id' => $this->it->id,
            'name' => 'Akses Akun',
        ]);
    }

    public function test_public_form_marks_external_categories(): void
    {
        $reporter = $this->publicReporter();
        $this->rememberReporter($reporter, str_repeat('A', 64), str_repeat('S', 40));

        $this->get(route('public-tickets.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('PublicTickets/Create')
                ->where('urls.externalForm', '/lapor/kategori/{category}/form')
                ->where('options.problem_categories', fn (Collection $categories): bool => $categories
                    ->firstWhere('id', $this->category->id)['is_external'] === true
                    && $categories->firstWhere('id', $this->internalCategory->id)['is_external'] === false));

        Http::assertNothingSent();
    }

    public function test_schema_endpoint_requires_identity_caches_and_never_leaks_token_or_identity(): void
    {
        Http::fake([self::BASE.'/forms/IK' => Http::response(['data' => $this->formDefinition()])]);

        $this->getJson(route('public-tickets.external-form', $this->category))
            ->assertStatus(401);
        Http::assertNothingSent();

        $reporter = $this->publicReporter();
        $this->rememberReporter($reporter, str_repeat('B', 64), str_repeat('S', 40));

        $response = $this->getJson(route('public-tickets.external-form', $this->category))
            ->assertOk()
            ->assertHeader('Cache-Control')
            ->assertJsonPath('data.key', 'IK')
            ->assertJsonPath('data.title', 'Instruksi Kerja (IK)')
            ->assertJsonCount(1, 'data.downloads')
            ->assertJsonPath('data.downloads.0.url', 'https://od.test/files/contoh-ik.pdf')
            // Nama pelapor diisi server saat dikirim; nilainya tidak dikirim ke browser.
            ->assertJsonPath('data.fields.0.key', 'nama')
            ->assertJsonPath('data.fields.0.auto', true)
            ->assertJsonMissingPath('data.fields.0.default')
            ->assertJsonMissingPath('data.fields.0.prefill')
            // Select prefill hanya mengirim nilai opsi yang cocok.
            ->assertJsonPath('data.fields.1.default', 'CS')
            ->assertJsonPath('data.fields.6.type', 'repeater')
            ->assertJsonPath('data.fields.6.fields.1.rules.accept', ['pdf']);

        $body = $response->getContent();
        $this->assertStringNotContainsString(self::TOKEN, (string) $body);
        $this->assertStringNotContainsString('Rina Pelapor', (string) $body);
        $this->assertStringNotContainsString('javascript:', (string) $body);
        $this->assertStringNotContainsString('base_url', (string) $body);
        $this->assertStringNotContainsString('od.test/api', (string) $body);

        $this->getJson(route('public-tickets.external-form', $this->category))->assertOk();
        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === self::BASE.'/forms/IK'
            && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN));

        $this->getJson(route('public-tickets.external-form', $this->internalCategory))
            ->assertNotFound();
        Http::assertSentCount(1);
    }

    public function test_schema_endpoint_reports_unavailable_and_unknown_forms_without_caching_failures(): void
    {
        $down = ProblemCategory::query()->create(['unit_id' => $this->busdev->id, 'name' => 'SOP', 'external_key' => 'SOP']);
        $gone = ProblemCategory::query()->create(['unit_id' => $this->busdev->id, 'name' => 'Memo', 'external_key' => 'MEMO']);
        Http::fake([
            self::BASE.'/forms/SOP' => Http::response(['message' => 'boom'], 500),
            self::BASE.'/forms/MEMO' => Http::response(['message' => 'Not found'], 404),
        ]);
        $this->rememberReporter($this->publicReporter(), str_repeat('C', 64), str_repeat('S', 40));

        $this->getJson(route('public-tickets.external-form', $down))
            ->assertStatus(503)
            ->assertJsonPath('message', ExternalUnavailableException::USER_MESSAGE);
        $this->getJson(route('public-tickets.external-form', $down))->assertStatus(503);
        $this->getJson(route('public-tickets.external-form', $gone))->assertNotFound();

        Http::assertSentCount(3);
    }

    public function test_public_submit_forwards_visible_answers_files_and_server_prefill(): void
    {
        Http::fake([
            self::BASE.'/forms/IK' => Http::response(['data' => $this->formDefinition()]),
            self::BASE.'/tickets' => Http::response(['data' => $this->snapshot('open', 'Diterima')], 201),
        ]);
        $reporter = $this->publicReporter();
        $this->rememberReporter($reporter, str_repeat('D', 64), str_repeat('S', 40));

        $this->post(route('public-tickets.store'), $this->externalPayload(str_repeat('S', 40)))
            ->assertRedirect(route('public-tickets.create'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('ticket_success', fn (array $success): bool => $success['title'] === 'IK Penerimaan Barang'
                && $success['unit'] === 'BUSDEV');

        $ticket = Ticket::query()->sole();
        $this->assertSame('IK-20261125-AB3D', $ticket->external_ref);
        $this->assertSame($reporter->id, (int) $ticket->owner_id);
        $this->assertSame($this->integration->id, (int) $ticket->integration_id);
        $this->assertNull(session(self::SUBMISSION_SESSION_KEY));

        Http::assertSent(function (HttpRequest $request) use ($reporter): bool {
            if ($request->url() !== self::BASE.'/tickets') {
                return false;
            }

            $parts = collect($request->data());
            $fields = $parts->filter(fn (array $part): bool => ! isset($part['filename']))
                ->mapWithKeys(fn (array $part): array => [$part['name'] => $part['contents']]);
            $files = $parts->filter(fn (array $part): bool => isset($part['filename']))
                ->map(fn (array $part): string => $part['name'].'='.$part['filename'])
                ->values()
                ->all();

            return $fields['idempotency_key'] === hash('sha256', 'public:'.$reporter->id.':form-fill-001')
                && $fields['category_key'] === 'IK'
                && $fields['title'] === 'IK Penerimaan Barang'
                && $fields['reporter[name]'] === 'Rina Pelapor'
                && $fields['answers[nama]'] === 'Rina Pelapor'
                && $fields['answers[badan_usaha]'] === 'CS'
                && $fields['answers[jenis]'] === 'baru'
                && $fields['answers[tujuan]'] === 'Standarisasi'
                && $fields['answers[proses][0][judul]'] === 'Terima barang'
                && $fields['answers[proses][1][judul]'] === 'Simpan barang'
                && ! $fields->has('answers[nomor_lama]')
                && ! $fields->has('answers[bogus]')
                && ! $fields->has('answers[catatan]')
                && $files === [
                    'answers[proses][0][lampiran]=alur.pdf',
                    'answers[dokumen][]=draft-1.pdf',
                    'answers[dokumen][]=draft-2.pdf',
                ];
        });
    }

    public function test_public_submit_maps_external_validation_errors_to_answer_keys(): void
    {
        Http::fake([
            self::BASE.'/forms/IK' => Http::response(['data' => $this->formDefinition()]),
            self::BASE.'/tickets' => Http::response([
                'message' => 'The given data was invalid.',
                'errors' => [
                    'answers.proses.0.judul' => ['Judul proses wajib diisi.'],
                    'answers.tujuan' => ['Tujuan terlalu singkat.'],
                ],
            ], 422),
        ]);
        $this->rememberReporter($this->publicReporter(), str_repeat('E', 64), str_repeat('S', 40));

        $this->from(route('public-tickets.create'))
            ->post(route('public-tickets.store'), $this->externalPayload(str_repeat('S', 40)))
            ->assertRedirect(route('public-tickets.create'))
            ->assertSessionHasErrors([
                'answers.proses.0.judul' => 'Judul proses wajib diisi.',
                'answers.tujuan' => 'Tujuan terlalu singkat.',
            ]);

        $this->assertDatabaseCount('tickets', 0);
        $this->assertSame(str_repeat('S', 40), session(self::SUBMISSION_SESSION_KEY));
    }

    public function test_public_submit_reports_unavailable_target_and_keeps_the_session(): void
    {
        Http::fake([
            self::BASE.'/forms/IK' => Http::response(['data' => $this->formDefinition()]),
            self::BASE.'/tickets' => Http::response(['message' => 'down'], 503),
        ]);
        $this->rememberReporter($this->publicReporter(), str_repeat('F', 64), str_repeat('S', 40));

        $this->from(route('public-tickets.create'))
            ->post(route('public-tickets.store'), $this->externalPayload(str_repeat('S', 40)))
            ->assertRedirect(route('public-tickets.create'))
            ->assertSessionHasErrors(['external' => ExternalUnavailableException::USER_MESSAGE]);

        $this->assertSame(0, Ticket::query()->count(), 'Hanya cadangan nomor tersembunyi yang tersisa.');
        $this->assertSame(str_repeat('S', 40), session(self::SUBMISSION_SESSION_KEY));
    }

    public function test_public_submit_is_idempotent_for_the_same_form_fill(): void
    {
        Http::fake([
            self::BASE.'/forms/IK' => Http::response(['data' => $this->formDefinition()]),
            self::BASE.'/tickets' => Http::response(['data' => $this->snapshot('open', 'Diterima')], 201),
        ]);
        $reporter = $this->publicReporter();
        $this->rememberReporter($reporter, str_repeat('G', 64), str_repeat('S', 40));

        $this->post(route('public-tickets.store'), $this->externalPayload(str_repeat('S', 40)))
            ->assertSessionHasNoErrors();

        $this->withSession([self::SUBMISSION_SESSION_KEY => str_repeat('T', 40)]);
        $this->post(route('public-tickets.store'), $this->externalPayload(str_repeat('T', 40)))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('ticket_success');

        $this->assertSame(1, Ticket::query()->count());
        Http::assertSentCount(2); // forms/IK sekali (cache) + tickets sekali.
    }

    public function test_public_submit_requires_an_idempotency_key_for_external_categories(): void
    {
        $this->rememberReporter($this->publicReporter(), str_repeat('H', 64), str_repeat('S', 40));
        $payload = $this->externalPayload(str_repeat('S', 40));
        unset($payload['idempotency_key']);

        $this->from(route('public-tickets.create'))
            ->post(route('public-tickets.store'), $payload)
            ->assertSessionHasErrors(['idempotency_key']);

        $this->assertDatabaseCount('tickets', 0);
        Http::assertNothingSent();
    }

    public function test_panel_create_submits_external_form_through_the_service(): void
    {
        $undoRepeaterFake = Repeater::fake();
        Http::fake([
            self::BASE.'/forms/IK' => Http::response(['data' => $this->formDefinition()]),
            self::BASE.'/tickets' => Http::response(['data' => $this->snapshot('in_progress', 'Drafting')], 201),
        ]);
        $finance = Unit::query()->create(['name' => 'Finance']);
        $user = $this->unitUser('Admin Unit', $finance, 'Admin Finance');
        $this->actingAs($user);

        $page = Livewire::test(CreateTicket::class)
            ->fillForm([
                'unit_id' => $this->busdev->id,
                'problem_category_id' => $this->category->id,
            ])
            // Prefill panel memakai identitas pengguna yang login.
            ->assertSet('data.answers.nama', 'Admin Finance')
            ->assertFormFieldIsHidden('description')
            ->assertFormFieldIsHidden('supporting_attachments')
            ->assertFormFieldIsHidden('priority_id')
            ->fillForm([
                'title' => 'IK Penerimaan Barang',
                'business_entities_id' => $this->entity->id,
                'answers.jenis' => 'revisi',
                'answers.nomor_lama' => 'IK-001',
                'answers.tujuan' => 'Standarisasi',
                'answers.proses' => [['judul' => 'Terima barang']],
            ])
            ->set('data.answers.dokumen', [UploadedFile::fake()->create('draft.pdf', 12, 'application/pdf')])
            ->call('create')
            ->assertHasNoFormErrors();

        $ticket = Ticket::query()->sole();
        $this->assertSame('IK-20261125-AB3D', $ticket->external_ref);
        $this->assertSame($user->id, (int) $ticket->owner_id);
        $this->assertNull($ticket->responsible_id);
        $key = (string) $page->get('externalIdempotencyKey');

        Http::assertSent(function (HttpRequest $request) use ($user, $key): bool {
            if ($request->url() !== self::BASE.'/tickets') {
                return false;
            }

            $parts = collect($request->data());
            $fields = $parts->filter(fn (array $part): bool => ! isset($part['filename']))
                ->mapWithKeys(fn (array $part): array => [$part['name'] => $part['contents']]);
            $files = $parts->filter(fn (array $part): bool => isset($part['filename']))
                ->map(fn (array $part): string => $part['name'].'='.$part['filename'])
                ->values()
                ->all();

            return $fields['idempotency_key'] === hash('sha256', 'panel:'.$user->id.':'.$key)
                && $fields['answers[nama]'] === 'Admin Finance'
                && $fields['answers[jenis]'] === 'revisi'
                && $fields['answers[nomor_lama]'] === 'IK-001'
                && $fields['answers[proses][0][judul]'] === 'Terima barang'
                && $files === ['answers[dokumen][]=draft.pdf'];
        });

        $undoRepeaterFake();
    }

    public function test_panel_create_shows_external_errors_on_components_and_keeps_input_when_unavailable(): void
    {
        $undoRepeaterFake = Repeater::fake();
        Http::fakeSequence(self::BASE.'/tickets')
            ->push([
                'message' => 'The given data was invalid.',
                'errors' => ['answers.proses.0.judul' => ['Judul proses sudah dipakai.']],
            ], 422)
            ->push(['message' => 'down'], 500);
        Http::fake([self::BASE.'/forms/IK' => Http::response(['data' => $this->formDefinition()])]);
        $finance = Unit::query()->create(['name' => 'Finance']);
        $this->actingAs($this->unitUser('Admin Unit', $finance, 'Admin Finance'));

        $page = Livewire::test(CreateTicket::class)
            ->fillForm([
                'unit_id' => $this->busdev->id,
                'problem_category_id' => $this->category->id,
            ])
            ->fillForm([
                'title' => 'IK Penerimaan Barang',
                'business_entities_id' => $this->entity->id,
                'answers.jenis' => 'baru',
                'answers.tujuan' => 'Standarisasi',
                'answers.proses' => [['judul' => 'Terima barang']],
            ]);
        $key = $page->get('externalIdempotencyKey');

        $page->call('create')
            ->assertHasFormErrors(['answers.proses.0.judul'])
            ->assertHasNoFormErrors(['description', 'priority_id']);
        $this->assertDatabaseCount('tickets', 0);

        $page->call('create')
            ->assertNotified(ExternalUnavailableException::USER_MESSAGE)
            ->assertSet('data.answers.tujuan', 'Standarisasi')
            ->assertSet('externalIdempotencyKey', $key);
        $this->assertSame(0, Ticket::query()->count(), 'Hanya cadangan nomor tersembunyi yang tersisa.');

        $undoRepeaterFake();
    }

    public function test_panel_create_with_unmapped_category_shows_notification_instead_of_500(): void
    {
        // Kategori lama yang belum punya external_key setelah unit diintegrasikan.
        $legacy = ProblemCategory::query()->create(['unit_id' => $this->busdev->id, 'name' => 'Kategori Lama']);
        $finance = Unit::query()->create(['name' => 'Finance']);
        $this->actingAs($this->unitUser('Admin Unit', $finance, 'Admin Finance'));

        Livewire::test(CreateTicket::class)
            ->fillForm([
                'unit_id' => $this->busdev->id,
                'problem_category_id' => $legacy->id,
            ])
            ->fillForm([
                'title' => 'Permintaan lama',
                'business_entities_id' => $this->entity->id,
            ])
            ->call('create')
            ->assertNotified('Kategori ini belum dipetakan ke sistem tujuan. Pilih kategori lain atau hubungi administrator.');

        $this->assertSame(0, Ticket::query()->withTrashed()->count());
        Http::assertNothingSent();
    }

    public function test_panel_file_fields_get_default_upload_caps(): void
    {
        [$files, $file, $strict] = ExternalTicketForm::fieldComponents([
            ['key' => 'dokumen', 'type' => 'files', 'label' => 'Dokumen', 'required' => false],
            ['key' => 'lampiran', 'type' => 'file', 'label' => 'Lampiran', 'required' => false],
            ['key' => 'ketat', 'type' => 'files', 'label' => 'Ketat', 'required' => false, 'rules' => ['max_files' => 500, 'max_kb' => 999999]],
        ], []);

        $this->assertSame(ExternalTicketService::MAX_FILES, $files->getMaxFiles());
        $this->assertSame(ExternalTicketService::MAX_FILE_KB, $files->getMaxSize());
        $this->assertSame(ExternalTicketService::MAX_FILE_KB, $file->getMaxSize());
        // Aturan eksternal tidak boleh melonggarkan batas helpdesk.
        $this->assertSame(ExternalTicketService::MAX_FILES, $strict->getMaxFiles());
        $this->assertSame(ExternalTicketService::MAX_FILE_KB, $strict->getMaxSize());
    }

    public function test_panel_create_rejects_attachments_over_total_size_cap(): void
    {
        $undoRepeaterFake = Repeater::fake();
        // Form eksternal tanpa max_files/max_kb: batas helpdesk tetap berlaku.
        $definition = $this->formDefinition();
        foreach ($definition['fields'] as $index => $field) {
            if ($field['key'] === 'dokumen') {
                unset($definition['fields'][$index]['rules']);
            }
        }
        Http::fake([self::BASE.'/forms/IK' => Http::response(['data' => $definition])]);
        $finance = Unit::query()->create(['name' => 'Finance']);
        $this->actingAs($this->unitUser('Admin Unit', $finance, 'Admin Finance'));

        Livewire::test(CreateTicket::class)
            ->fillForm([
                'unit_id' => $this->busdev->id,
                'problem_category_id' => $this->category->id,
            ])
            ->fillForm([
                'title' => 'IK Penerimaan Barang',
                'business_entities_id' => $this->entity->id,
                'answers.jenis' => 'baru',
                'answers.tujuan' => 'Standarisasi',
                'answers.proses' => [['judul' => 'Terima barang']],
            ])
            ->set('data.answers.dokumen', collect(range(1, 5))
                ->map(fn (int $i): UploadedFile => UploadedFile::fake()->create("dok-{$i}.pdf", 11000, 'application/pdf'))
                ->all())
            ->call('create')
            ->assertHasFormErrors(['answers'])
            ->assertNotified('Total ukuran seluruh lampiran maksimal 50 MB.');

        $this->assertSame(0, Ticket::query()->withTrashed()->count());
        Http::assertNotSent(fn (HttpRequest $request): bool => $request->url() === self::BASE.'/tickets');

        $undoRepeaterFake();
    }

    public function test_panel_internal_create_is_unchanged(): void
    {
        $finance = Unit::query()->create(['name' => 'Finance']);
        $this->actingAs($this->unitUser('Admin Unit', $finance, 'Admin Finance'));

        Livewire::test(CreateTicket::class)
            ->fillForm([
                'unit_id' => $this->it->id,
                'problem_category_id' => $this->internalCategory->id,
                'title' => 'Tidak bisa login',
                'description' => '<p>Sejak pagi.</p>',
                'priority_id' => $this->priority->id,
                'business_entities_id' => $this->entity->id,
            ])
            ->assertFormFieldIsVisible('description')
            ->assertFormFieldIsVisible('priority_id')
            ->call('create')
            ->assertHasNoFormErrors();

        $ticket = Ticket::query()->sole();
        $this->assertNull($ticket->integration_id);
        Http::assertNothingSent();
    }

    public function test_view_external_ticket_shows_snapshot_hides_workflow_and_refreshes(): void
    {
        Http::fake([
            self::BASE.'/tickets' => Http::response(['data' => $this->snapshot('in_progress', 'Drafting')], 201),
            self::BASE.'/tickets/IK-20261125-AB3D' => Http::response(['data' => $this->snapshot('done', 'Selesai', '2026-11-25T12:00:00+07:00')]),
        ]);
        $finance = Unit::query()->create(['name' => 'Finance']);
        $owner = $this->unitUser('Admin Unit', $finance, 'Admin Finance');
        $ticket = app(ExternalTicketService::class)->submit(
            $this->category,
            $owner,
            'IK Penerimaan Barang',
            ['tujuan' => 'Standarisasi'],
            [],
            'view-001',
        );
        $staff = $this->unitUser('Staff Unit', $this->busdev, 'Staff OD');
        $this->actingAs($staff);

        Livewire::test(ViewTicket::class, ['record' => $ticket->getRouteKey()])
            ->assertActionDoesNotExist('proses')
            ->assertActionDoesNotExist('cancel')
            ->assertActionDoesNotExist('selesai')
            ->assertActionDoesNotExist('pindah_unit')
            ->assertActionDoesNotExist('ubah_kategori')
            ->assertActionDoesNotExist('edit')
            ->assertActionVisible('muat_ulang_eksternal')
            ->assertSee('IK-20261125-AB3D')
            ->assertSee('Drafting')
            ->assertSee('IK Penerimaan Barang · Drafting · PIC: Tim OD')
            ->assertSee('Pengajuan diterima')
            ->assertSee('Mohon lampirkan SOP lama.')
            ->assertSee('IK-Final.pdf')
            ->assertSee('https://od.test/files/ik-final.pdf')
            ->callAction('muat_ulang_eksternal')
            ->assertNotified('Data tiket diperbarui dari sistem tujuan.');

        $ticket->refresh();
        $this->assertSame(TicketStatus::CLOSED, (int) $ticket->ticket_statuses_id);
        $this->assertSame('Selesai', $ticket->external_status_label);

        // Pelapor melihat label status sistem tujuan di daftar tiket keluarnya.
        $this->actingAs($owner);
        Livewire::test(ListTickets::class)
            ->set('activeTab', 'keluar')
            ->assertCanSeeTableRecords([$ticket])
            ->assertSee('Selesai');
    }

    public function test_super_admin_cannot_edit_or_move_external_ticket(): void
    {
        Http::fake([
            self::BASE.'/tickets' => Http::response(['data' => $this->snapshot('in_progress', 'Drafting')], 201),
        ]);
        $finance = Unit::query()->create(['name' => 'Finance']);
        $owner = $this->unitUser('Admin Unit', $finance, 'Admin Finance');
        $ticket = app(ExternalTicketService::class)->submit(
            $this->category,
            $owner,
            'IK Penerimaan Barang',
            ['tujuan' => 'Standarisasi'],
            [],
            'edit-001',
        );
        $superAdmin = $this->unitUser('Super Admin', $this->it, 'Super Admin QA');
        $this->actingAs($superAdmin);

        // Gate::before untuk Super Admin tidak boleh membuka jalan mengubah
        // atau memproses tiket eksternal.
        $this->assertFalse($superAdmin->can('update', $ticket));
        $this->assertFalse($superAdmin->can('process', $ticket));

        Livewire::test(ListTickets::class)
            ->assertTableActionHidden('edit', $ticket);

        Livewire::test(EditTicket::class, ['record' => $ticket->getRouteKey()])
            ->assertForbidden();

        $ticket->refresh();
        $this->assertSame($this->busdev->id, (int) $ticket->unit_id);
        $this->assertSame($this->category->id, (int) $ticket->problem_category_id);
        $this->assertSame($this->integration->id, (int) $ticket->integration_id);
    }

    public function test_schema_helper_prunes_nested_visibility_and_maps_repeater_error_paths(): void
    {
        $schemas = app(ExternalFormSchema::class);
        $form = $schemas->sanitize([
            'key' => 'IK',
            'fields' => [
                ['key' => 'jenis', 'type' => 'select', 'label' => 'Jenis', 'options' => [['value' => 'revisi', 'label' => 'Revisi']]],
                ['key' => 'Bad Key', 'type' => 'text', 'label' => 'Dibuang'],
                ['key' => 'aneh', 'type' => 'checkbox', 'label' => 'Tipe tidak dikenal'],
                [
                    'key' => 'proses',
                    'type' => 'repeater',
                    'label' => 'Proses',
                    'fields' => [
                        ['key' => 'judul', 'type' => 'text', 'label' => 'Judul'],
                        // visible_if ke field level atas.
                        ['key' => 'alasan', 'type' => 'text', 'label' => 'Alasan', 'visible_if' => ['field' => 'jenis', 'equals' => 'revisi']],
                        ['key' => 'lampiran', 'type' => 'file', 'label' => 'Lampiran'],
                    ],
                ],
            ],
        ]);

        $this->assertSame(['jenis', 'proses'], array_column($form['fields'], 'key'));

        $file = UploadedFile::fake()->create('a.pdf', 1, 'application/pdf');
        $reporter = $this->publicReporter();
        [$answers, $files] = $schemas->prepareAnswers($form, [
            'jenis' => 'baru',
            'proses' => ['x' => ['judul' => 'A', 'alasan' => 'tidak dikirim'], 'y' => ['judul' => 'B']],
        ], ['proses' => ['x' => ['lampiran' => $file]]], $reporter, autoPrefill: true);

        $this->assertSame(['jenis' => 'baru', 'proses' => [['judul' => 'A'], ['judul' => 'B']]], $answers);
        $this->assertSame(['proses' => [0 => ['lampiran' => $file]]], $files);

        [$answers] = $schemas->prepareAnswers($form, [
            'jenis' => 'revisi',
            'proses' => [['judul' => 'A', 'alasan' => 'Perubahan SOP']],
        ], [], $reporter, autoPrefill: true);
        $this->assertSame('Perubahan SOP', $answers['proses'][0]['alasan']);

        $state = ['proses' => ['uuid-a' => ['judul' => 'A'], 'uuid-b' => ['judul' => 'B']]];
        $this->assertSame('answers.proses.uuid-b.judul', ExternalFormSchema::componentErrorPath($form, 'answers.proses.1.judul', $state));
        $this->assertSame('answers.proses.uuid-a.lampiran', ExternalFormSchema::componentErrorPath($form, 'answers.proses.0.lampiran.0', $state));
        $this->assertSame('answers', ExternalFormSchema::componentErrorPath($form, 'answers.tidak_ada', $state));
        $this->assertNull(ExternalFormSchema::componentErrorPath($form, 'title', $state));
    }

    /**
     * @return array<string, mixed>
     */
    private function externalPayload(string $submissionToken): array
    {
        return [
            'submission_token' => $submissionToken,
            'website' => '',
            'business_entities_id' => $this->entity->id,
            'unit_id' => $this->busdev->id,
            'problem_category_id' => $this->category->id,
            'title' => 'IK Penerimaan Barang',
            'idempotency_key' => 'form-fill-001',
            'answers' => [
                'jenis' => 'baru',
                'nomor_lama' => 'TIDAK-BOLEH-TERKIRIM',
                'tujuan' => 'Standarisasi',
                'bogus' => 'x',
                'catatan' => 'x',
                'proses' => [
                    ['judul' => 'Terima barang', 'lampiran' => UploadedFile::fake()->create('alur.pdf', 10, 'application/pdf')],
                    ['judul' => 'Simpan barang'],
                ],
                'dokumen' => [
                    UploadedFile::fake()->create('draft-1.pdf', 5, 'application/pdf'),
                    UploadedFile::fake()->create('draft-2.pdf', 5, 'application/pdf'),
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formDefinition(): array
    {
        return [
            'key' => 'IK',
            'title' => 'Instruksi Kerja (IK)',
            'description' => 'Isi sesuai template.',
            'downloads' => [
                ['label' => 'Contoh IK', 'url' => 'https://od.test/files/contoh-ik.pdf'],
                ['label' => 'Jahat', 'url' => 'javascript:alert(1)'],
            ],
            'fields' => [
                ['key' => 'nama', 'type' => 'text', 'label' => 'Nama pemohon', 'required' => true, 'prefill' => 'reporter.name'],
                [
                    'key' => 'badan_usaha',
                    'type' => 'select',
                    'label' => 'Badan usaha',
                    'required' => false,
                    'prefill' => 'reporter.business_entity',
                    'options' => [
                        ['value' => 'CS', 'label' => 'Complete Selular'],
                        ['value' => 'LAIN', 'label' => 'Lainnya'],
                    ],
                ],
                [
                    'key' => 'jenis',
                    'type' => 'radio',
                    'label' => 'Jenis pengajuan',
                    'required' => true,
                    'options' => [
                        ['value' => 'baru', 'label' => 'Baru'],
                        ['value' => 'revisi', 'label' => 'Revisi'],
                    ],
                ],
                [
                    'key' => 'nomor_lama',
                    'type' => 'text',
                    'label' => 'Nomor IK lama',
                    'required' => true,
                    'visible_if' => ['field' => 'jenis', 'equals' => 'revisi'],
                ],
                ['key' => 'tujuan', 'type' => 'textarea', 'label' => 'Tujuan', 'required' => true, 'rules' => ['max' => 500]],
                ['key' => 'catatan', 'type' => 'info', 'label' => 'Catatan', 'help' => 'Baca panduan dulu.'],
                [
                    'key' => 'proses',
                    'type' => 'repeater',
                    'label' => 'Proses',
                    'required' => true,
                    'min_items' => 1,
                    'max_items' => 5,
                    'fields' => [
                        ['key' => 'judul', 'type' => 'text', 'label' => 'Judul proses', 'required' => true],
                        ['key' => 'lampiran', 'type' => 'file', 'label' => 'Lampiran', 'required' => false, 'rules' => ['accept' => ['pdf'], 'max_kb' => 2048]],
                    ],
                ],
                ['key' => 'dokumen', 'type' => 'files', 'label' => 'Dokumen', 'required' => false, 'rules' => ['max_files' => 3]],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(string $code, string $label, string $updatedAt = '2026-11-25T10:00:00+07:00'): array
    {
        return [
            'ref' => 'IK-20261125-AB3D',
            'url' => 'https://od.test/ik/IK-20261125-AB3D',
            'status' => ['code' => $code, 'label' => $label],
            'summary' => [['label' => 'Kategori', 'value' => 'Instruksi Kerja']],
            'items' => [['title' => 'IK Penerimaan Barang', 'status' => ['code' => $code, 'label' => $label], 'pic' => 'Tim OD']],
            'timeline' => [['at' => '2026-11-25T10:00:00+07:00', 'label' => 'Pengajuan diterima', 'note' => null]],
            'comments' => [['id' => '12', 'author' => 'Tim OD', 'body' => 'Mohon lampirkan SOP lama.', 'at' => '2026-11-25T11:00:00+07:00']],
            'files' => [['name' => 'IK-Final.pdf', 'url' => 'https://od.test/files/ik-final.pdf']],
            'updated_at' => $updatedAt,
        ];
    }

    private function publicReporter(): User
    {
        return User::query()->create([
            'name' => 'Rina Pelapor',
            'email' => null,
            'password' => null,
            'phone' => '6281234500777',
            'business_entity_id' => $this->entity->id,
            'is_active' => true,
        ]);
    }

    private function rememberReporter(User $reporter, string $deviceToken, string $submissionToken): void
    {
        $this->assertTrue(app(HelpdeskReporterIdentityService::class)->bind(
            'public-helpdesk-form',
            'web',
            $deviceToken,
            $reporter,
            'phone_input',
        ));
        $this->withCookie(PublicReporterIdentityService::COOKIE_NAME, $deviceToken);
        $this->withSession([
            self::DEVICE_SESSION_KEY => $deviceToken,
            self::SUBMISSION_SESSION_KEY => $submissionToken,
        ]);
    }

    private function unitUser(string $role, Unit $unit, string $name): User
    {
        $user = User::query()->create([
            'unit_id' => $unit->id,
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
            'email_verified_at' => now(),
            'email_verified_via' => 'admin',
            'password' => 'secret',
            'is_active' => true,
        ]);
        $user->assignRole($role);
        $user->units()->attach($unit->id);

        return $user->refresh();
    }

    private function createAuthorizationSchema(): void
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

    private function seedTicketPermissionsAndRoles(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['ViewAny:Ticket', 'View:Ticket', 'Create:Ticket', 'Update:Ticket', 'Delete:Ticket', 'ViewAny:Comment', 'View:Comment', 'Create:Comment'] as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'web']);
        }

        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
