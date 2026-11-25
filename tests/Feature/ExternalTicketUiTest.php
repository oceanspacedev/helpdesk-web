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
use App\Services\Integrations\HelpdeskReporterRegistrationService;
use App\Services\Integrations\PublicReporterIdentityService;
use App\Services\WhatsAppGateway;
use App\Support\HelpdeskReporterName;
use App\Support\HelpdeskWhatsAppMessage;
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
            // Field badan usaha dijawab lewat pertanyaan badan usaha helpdesk.
            ->assertJsonPath('data.fields.1.key', 'badan_usaha')
            ->assertJsonPath('data.fields.1.ticket', true)
            ->assertJsonPath('data.fields.1.business_entity', true)
            ->assertJsonMissingPath('data.fields.1.prefill')
            ->assertJsonMissingPath('data.fields.1.auto')
            ->assertJsonPath('data.business_entity.key', 'badan_usaha')
            ->assertJsonPath('data.business_entity.required', false)
            ->assertJsonPath('data.business_entity.options', [
                ['value' => 'CS', 'label' => 'Complete Selular'],
                ['value' => 'LAIN', 'label' => 'Lainnya'],
            ])
            // Pilihan awal: opsi yang cocok dengan badan usaha kontrak pelapor.
            ->assertJsonPath('data.business_entity.default', 'CS')
            // Pelapor hanya-nomor-HP: identitas yang tidak diketahui tetap tampil.
            ->assertJsonPath('data.fields.6.fields.1.key', 'pic_email')
            ->assertJsonMissingPath('data.fields.6.fields.1.auto')
            ->assertJsonPath('data.fields.8.key', 'email')
            ->assertJsonMissingPath('data.fields.8.auto')
            ->assertJsonMissingPath('data.fields.9.auto')
            ->assertJsonMissingPath('data.fields.10.auto')
            ->assertJsonMissingPath('data.fields.11.auto')
            ->assertJsonPath('data.fields.6.type', 'repeater')
            ->assertJsonPath('data.fields.6.fields.2.rules.accept', ['pdf']);

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

    public function test_schema_endpoint_marks_known_employee_identity_as_auto_without_values(): void
    {
        Http::fake([self::BASE.'/forms/IK' => Http::response(['data' => $this->formDefinition()])]);
        $reporter = $this->talentaReporter();
        $this->rememberReporter($reporter, str_repeat('K', 64), str_repeat('S', 40));

        $response = $this->getJson(route('public-tickets.external-form', $this->category))
            ->assertOk()
            ->assertJsonPath('data.fields.6.fields.1.auto', true)
            ->assertJsonPath('data.fields.8.key', 'email')
            ->assertJsonPath('data.fields.8.auto', true)
            ->assertJsonPath('data.fields.9.key', 'divisi')
            ->assertJsonPath('data.fields.9.auto', true)
            // Select: "manager" cocok (tanpa beda huruf besar/kecil) dengan opsi Manager.
            ->assertJsonPath('data.fields.10.key', 'jabatan')
            ->assertJsonPath('data.fields.10.auto', true)
            ->assertJsonMissingPath('data.fields.10.default')
            ->assertJsonMissingPath('data.fields.10.auto_value')
            ->assertJsonMissingPath('data.fields.10.prefill')
            ->assertJsonPath('data.fields.11.key', 'nik')
            ->assertJsonPath('data.fields.11.auto', true);

        $body = (string) $response->getContent();
        foreach (['Rina Pelapor', 'rina@complete.test', '3201000000001', 'Marketing Area'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }

        // Jabatan tanpa opsi yang cocok tetap tampil untuk diisi pelapor.
        $reporter->forceFill(['job_level' => 'Direktur'])->save();
        $this->getJson(route('public-tickets.external-form', $this->category))
            ->assertOk()
            ->assertJsonPath('data.fields.10.key', 'jabatan')
            ->assertJsonMissingPath('data.fields.10.auto')
            ->assertJsonPath('data.fields.8.auto', true);
    }

    public function test_public_submit_fills_auto_fields_and_answers_business_entity_with_the_external_option(): void
    {
        $second = ['ref' => 'IK-20261125-ZZ99', 'url' => 'https://od.test/ik/IK-20261125-ZZ99'] + $this->snapshot('open', 'Diterima');
        Http::fakeSequence(self::BASE.'/tickets')
            ->push(['data' => $this->snapshot('open', 'Diterima')], 201)
            ->push(['data' => $second], 201);
        Http::fake([self::BASE.'/forms/IK' => Http::response(['data' => $this->formDefinition()])]);
        $other = BusinessEntity::query()->create(['name' => 'PT Mitra Lain']);
        $reporter = $this->talentaReporter();
        $this->rememberReporter($reporter, str_repeat('L', 64), str_repeat('S', 40));

        // Browser tidak mengirim field otomatis/badan usaha; nilai palsu ditimpa server.
        $payload = $this->externalPayload(str_repeat('S', 40));
        $payload['business_entities_id'] = $other->id; // diabaikan: form punya field badan usaha
        $payload['external_business_entity'] = 'CS';
        $payload['answers']['badan_usaha'] = 'LAIN';
        $payload['answers']['email'] = 'palsu@example.test';
        $this->post(route('public-tickets.store'), $payload)->assertSessionHasNoErrors();

        // Opsi tanpa badan usaha helpdesk yang sama namanya: tiket tanpa badan usaha.
        $this->withSession([self::SUBMISSION_SESSION_KEY => str_repeat('T', 40)]);
        $payload = $this->externalPayload(str_repeat('T', 40));
        $payload['idempotency_key'] = 'form-fill-002';
        $payload['external_business_entity'] = 'LAIN';
        $this->post(route('public-tickets.store'), $payload)->assertSessionHasNoErrors();

        $sent = $this->sentTicketFields();
        $this->assertCount(2, $sent);
        $this->assertSame('CS', $sent[0]['answers[badan_usaha]']);
        $this->assertSame('rina@complete.test', $sent[0]['answers[email]']);
        $this->assertSame('Marketing Area', $sent[0]['answers[divisi]']);
        $this->assertSame('Manager', $sent[0]['answers[jabatan]']);
        $this->assertSame('3201000000001', $sent[0]['answers[nik]']);
        $this->assertSame('Rina Pelapor', $sent[0]['answers[nama]']);
        $this->assertSame('rina@complete.test', $sent[0]['answers[proses][0][pic_email]']);
        $this->assertSame('rina@complete.test', $sent[0]['answers[proses][1][pic_email]']);
        $this->assertSame('LAIN', $sent[1]['answers[badan_usaha]']);
        $this->assertSame(0, Ticket::query()->withTrashed()->count());
    }

    public function test_public_submit_validates_the_external_business_entity_option(): void
    {
        $definition = $this->formDefinition();
        $definition['fields'][1]['required'] = true;
        Http::fake([
            self::BASE.'/forms/IK' => Http::response(['data' => $definition]),
            self::BASE.'/tickets' => Http::response(['data' => $this->snapshot('open', 'Diterima')], 201),
        ]);
        $this->rememberReporter($this->publicReporter(), str_repeat('V', 64), str_repeat('S', 40));

        $payload = $this->externalPayload(str_repeat('S', 40));
        $payload['external_business_entity'] = 'PT SMI'; // bukan opsi form terbaru
        $this->from(route('public-tickets.create'))
            ->post(route('public-tickets.store'), $payload)
            ->assertSessionHasErrors(['external_business_entity' => 'Pilihan badan usaha tidak valid. Muat ulang halaman.']);

        $payload['external_business_entity'] = '';
        $this->from(route('public-tickets.create'))
            ->post(route('public-tickets.store'), $payload)
            ->assertSessionHasErrors(['external_business_entity' => 'Badan usaha wajib dipilih.']);

        $this->assertSame(0, Ticket::query()->withTrashed()->count());
        Http::assertNotSent(fn (HttpRequest $request): bool => $request->url() === self::BASE.'/tickets');
    }

    public function test_public_submit_shows_external_business_entity_errors_on_the_business_entity_question(): void
    {
        Http::fake([
            self::BASE.'/forms/IK' => Http::response(['data' => $this->formDefinition()]),
            self::BASE.'/tickets' => Http::response([
                'message' => 'The given data was invalid.',
                'errors' => ['answers.badan_usaha' => ['Badan usaha tidak aktif.']],
            ], 422),
        ]);
        $this->rememberReporter($this->publicReporter(), str_repeat('W', 64), str_repeat('S', 40));

        $this->from(route('public-tickets.create'))
            ->post(route('public-tickets.store'), $this->externalPayload(str_repeat('S', 40)))
            ->assertSessionHasErrors(['external_business_entity' => 'Badan usaha tidak aktif.'])
            ->assertSessionDoesntHaveErrors(['answers.badan_usaha']);
    }

    public function test_external_form_without_business_entity_field_keeps_the_helpdesk_business_entity_question(): void
    {
        $definition = $this->formDefinition();
        // Field badan usaha teks: diisi dari nama badan usaha helpdesk terpilih.
        $definition['fields'][1] = ['key' => 'badan_usaha', 'type' => 'text', 'label' => 'Badan usaha', 'required' => true, 'prefill' => 'ticket.business_entity'];
        Http::fake([
            self::BASE.'/forms/IK' => Http::response(['data' => $definition]),
            self::BASE.'/tickets' => Http::response([
                'message' => 'The given data was invalid.',
                'errors' => ['answers.badan_usaha' => ['Badan usaha tidak dikenal.']],
            ], 422),
        ]);
        $other = BusinessEntity::query()->create(['name' => 'PT Mitra Lain']);
        $this->rememberReporter($this->publicReporter(), str_repeat('X', 64), str_repeat('S', 40));

        $this->getJson(route('public-tickets.external-form', $this->category))
            ->assertOk()
            ->assertJsonPath('data.business_entity', null)
            ->assertJsonPath('data.fields.1.ticket', true)
            ->assertJsonMissingPath('data.fields.1.business_entity');

        $payload = $this->externalPayload(str_repeat('S', 40));
        unset($payload['business_entities_id']);
        $this->from(route('public-tickets.create'))
            ->post(route('public-tickets.store'), $payload)
            ->assertSessionHasErrors(['business_entities_id' => 'Badan usaha wajib dipilih.']);
        Http::assertNotSent(fn (HttpRequest $request): bool => $request->url() === self::BASE.'/tickets');

        $payload['business_entities_id'] = $other->id;
        $payload['external_business_entity'] = 'CS'; // diabaikan
        $this->from(route('public-tickets.create'))
            ->post(route('public-tickets.store'), $payload)
            ->assertSessionHasErrors(['business_entities_id' => 'Badan usaha tidak dikenal.']);

        $this->assertSame('PT Mitra Lain', $this->sentTicketFields()[0]['answers[badan_usaha]']);
    }

    public function test_schema_helper_resolves_new_prefill_sources(): void
    {
        $schemas = app(ExternalFormSchema::class);
        $reporter = $this->talentaReporter();
        $select = fn (string $source): array => [
            'key' => 'x', 'type' => 'select', 'label' => 'X', 'prefill' => $source,
            'options' => [['value' => 'CS', 'label' => 'complete selular'], ['value' => 'MGR', 'label' => 'Manager']],
        ];

        $this->assertSame('3201000000001', $schemas->prefillValue(['key' => 'n', 'type' => 'text', 'prefill' => 'reporter.nik'], $reporter));
        $this->assertSame('manager', $schemas->prefillValue(['key' => 'j', 'type' => 'text', 'prefill' => 'reporter.job_level'], $reporter));
        $this->assertSame('MGR', $schemas->prefillValue($select('reporter.job_level'), $reporter));
        // ticket.business_entity dijawab lewat pertanyaan badan usaha helpdesk, bukan prefill.
        $this->assertNull($schemas->prefillValue($select('ticket.business_entity'), $reporter));
        $this->assertNull($schemas->prefillValue($select('reporter.tidak_dikenal'), $reporter));
        // File tidak pernah diisi otomatis.
        $this->assertNull($schemas->prefillValue(['key' => 'f', 'type' => 'file', 'prefill' => 'reporter.nik'], $reporter));
    }

    public function test_schema_helper_resolves_business_entity_answers(): void
    {
        $form = app(ExternalFormSchema::class)->sanitize([
            'key' => 'IK',
            'fields' => [
                ['key' => 'catatan_bu', 'type' => 'text', 'label' => 'BU', 'prefill' => 'ticket.business_entity'],
                [
                    'key' => 'area', 'type' => 'select', 'label' => 'Area', 'prefill' => 'ticket.business_entity',
                    'options' => [['value' => 'cs', 'label' => 'CV CS'], ['value' => 'csn', 'label' => 'PT CSN'], ['value' => 'smi', 'label' => 'PT SMI']],
                ],
                [
                    'key' => 'baris', 'type' => 'repeater', 'label' => 'Baris',
                    'fields' => [['key' => 'bu', 'type' => 'select', 'label' => 'BU', 'prefill' => 'ticket.business_entity', 'options' => [['value' => 'X', 'label' => 'pt smi']]]],
                ],
            ],
        ]);
        $marked = ExternalFormSchema::markTicketFields($form);
        $field = ExternalFormSchema::businessEntityField($form);

        // Select/radio level atas pertama; field teks dan di repeater hanya ditandai `ticket`.
        $this->assertSame('area', $field['key']);
        $this->assertTrue($marked['fields'][0]['ticket']);
        $this->assertArrayNotHasKey('business_entity', $marked['fields'][0]);
        $this->assertArrayNotHasKey('business_entity', $marked['fields'][2]['fields'][0]);

        $smi = BusinessEntity::query()->create(['name' => 'pt smi']);
        $this->assertSame($smi->id, ExternalFormSchema::matchingBusinessEntityId(ExternalFormSchema::businessEntityOption($field, 'smi')));
        $this->assertNull(ExternalFormSchema::matchingBusinessEntityId(ExternalFormSchema::businessEntityOption($field, 'csn')));
        $this->assertNull(ExternalFormSchema::businessEntityOption($field, 'PT SMI'), 'Hanya nilai opsi yang diterima.');

        [$answers] = app(ExternalFormSchema::class)->prepareAnswers($form, [
            'area' => 'cs', 'catatan_bu' => 'palsu', 'baris' => [['bu' => 'palsu']],
        ], [], $this->publicReporter(), $this->entity->id, 'smi');
        $this->assertSame(['catatan_bu' => 'PT SMI', 'area' => 'smi', 'baris' => [['bu' => 'X']]], $answers);

        $this->assertSame('external_business_entity', ExternalFormSchema::businessEntityErrorKey($form, 'answers.area'));
        $this->assertSame('external_business_entity', ExternalFormSchema::businessEntityErrorKey($form, 'answers.catatan_bu'));
        $this->assertNull(ExternalFormSchema::businessEntityErrorKey($form, 'answers.baris.0.bu'));
        $this->assertNull(ExternalFormSchema::businessEntityErrorKey($form, 'title'));
    }

    public function test_schema_endpoint_defaults_the_business_entity_option_case_insensitively(): void
    {
        $definition = $this->formDefinition();
        $definition['fields'][1]['options'] = [['value' => 'cv_cs', 'label' => 'CV CS'], ['value' => 'COMPLETE SELULAR', 'label' => 'Kantor pusat']];
        Http::fake([self::BASE.'/forms/IK' => Http::response(['data' => $definition])]);
        $this->rememberReporter($this->publicReporter(), str_repeat('Y', 64), str_repeat('S', 40));

        $this->getJson(route('public-tickets.external-form', $this->category))
            ->assertOk()
            ->assertJsonPath('data.business_entity.default', 'COMPLETE SELULAR');

        // Badan usaha kontrak tanpa opsi yang cocok: tanpa pilihan awal.
        $this->entity->update(['name' => 'PT Lain']);
        Cache::flush();
        $this->getJson(route('public-tickets.external-form', $this->category))
            ->assertOk()
            ->assertJsonPath('data.business_entity.default', null);
    }

    public function test_schema_endpoint_precomputes_visibility_that_depends_on_auto_fields(): void
    {
        Http::fake([self::BASE.'/forms/IK' => Http::response(['data' => $this->conditionalFormDefinition()])]);
        $this->rememberReporter($this->talentaReporter(), str_repeat('M', 64), str_repeat('S', 40));

        $this->getJson(route('public-tickets.external-form', $this->category))
            ->assertOk()
            ->assertJsonPath('data.fields.0.key', 'jabatan')
            ->assertJsonPath('data.fields.0.auto', true)
            // Browser tidak tahu nilai jabatan: hasil visible_if dihitung server.
            ->assertJsonPath('data.fields.1.key', 'approval_direksi')
            ->assertJsonPath('data.fields.1.visible', true)
            ->assertJsonMissingPath('data.fields.1.visible_if')
            ->assertJsonPath('data.fields.2.key', 'catatan_staff')
            ->assertJsonPath('data.fields.2.visible', false)
            ->assertJsonMissingPath('data.fields.2.visible_if')
            // Acuan ke field non-otomatis/badan usaha tetap dievaluasi di browser.
            ->assertJsonPath('data.fields.4.visible_if.field', 'badan_usaha')
            ->assertJsonPath('data.fields.6.visible_if.field', 'perlu_approval')
            // Unit lebih panjang dari rules.max: tetap input biasa, tanpa nilai.
            ->assertJsonPath('data.fields.7.key', 'divisi')
            ->assertJsonMissingPath('data.fields.7.auto')
            ->assertJsonMissingPath('data.fields.7.default')
            ->assertJsonPath('data.fields.8.auto', true);
    }

    public function test_public_submit_keeps_reporter_input_for_prefill_values_that_break_field_rules(): void
    {
        Http::fake([
            self::BASE.'/forms/IK' => Http::response(['data' => $this->conditionalFormDefinition()]),
            self::BASE.'/tickets' => Http::response(['data' => $this->snapshot('open', 'Diterima')], 201),
        ]);
        $this->rememberReporter($this->talentaReporter(), str_repeat('N', 64), str_repeat('S', 40));

        $payload = $this->externalPayload(str_repeat('S', 40));
        $payload['answers'] = [
            'approval_direksi' => 'Disetujui',
            'nomor_cs' => 'CS-1',
            'perlu_approval' => 'tidak',
            'divisi' => 'MKT',
        ];
        $this->post(route('public-tickets.store'), $payload)->assertSessionHasNoErrors();

        $sent = $this->sentTicketFields()[0];
        $this->assertSame('Manager', $sent['answers[jabatan]']);
        $this->assertSame('Disetujui', $sent['answers[approval_direksi]']);
        $this->assertSame('CS', $sent['answers[badan_usaha]']);
        $this->assertSame('CS-1', $sent['answers[nomor_cs]']);
        $this->assertSame('MKT', $sent['answers[divisi]']);
        $this->assertSame('rina@complete.test', $sent['answers[email]']);
        $this->assertFalse($sent->has('answers[catatan_staff]'));
        $this->assertFalse($sent->has('answers[email_atasan]'));
    }

    public function test_schema_helper_only_auto_fills_values_that_pass_field_rules(): void
    {
        $text = ['key' => 't', 'type' => 'text', 'rules' => ['max' => 5]];
        $this->assertSame('Abc', ExternalFormSchema::autoValue($text, ' Abc '));
        $this->assertNull(ExternalFormSchema::autoValue($text, 'Marketing'));
        $this->assertNull(ExternalFormSchema::autoValue(['key' => 't', 'type' => 'text', 'rules' => ['min' => 5]], 'Abc'));
        $this->assertNull(ExternalFormSchema::autoValue(['key' => 'e', 'type' => 'email'], 'bukan-email'));
        $this->assertSame('a@b.test', ExternalFormSchema::autoValue(['key' => 'e', 'type' => 'email'], 'a@b.test'));
        $this->assertNull(ExternalFormSchema::autoValue(['key' => 'e', 'type' => 'email', 'rules' => ['max' => 5]], 'a@b.test'));
        $this->assertNull(ExternalFormSchema::autoValue(['key' => 'n', 'type' => 'number'], '12a'));
        $this->assertNull(ExternalFormSchema::autoValue(['key' => 'n', 'type' => 'number', 'rules' => ['max' => 10]], '11'));
        $this->assertSame('10', ExternalFormSchema::autoValue(['key' => 'n', 'type' => 'number', 'rules' => ['max' => 10]], '10'));
        $this->assertNull(ExternalFormSchema::autoValue(['key' => 'd', 'type' => 'date'], '2026-02-30'));
        $this->assertSame('2026-02-03', ExternalFormSchema::autoValue(['key' => 'd', 'type' => 'date'], '2026-02-03'));
    }

    public function test_panel_visibility_and_auto_note_use_the_values_helpdesk_fills(): void
    {
        $undoRepeaterFake = Repeater::fake();
        Http::fake([
            self::BASE.'/forms/IK' => Http::response(['data' => $this->conditionalFormDefinition()]),
            self::BASE.'/tickets' => Http::response(['data' => $this->snapshot('open', 'Diterima')], 201),
        ]);
        $finance = Unit::query()->create(['name' => 'Finance']);
        $user = $this->unitUser('Admin Unit', $finance, 'Admin Finance');
        $user->forceFill(['job_level' => 'Manager'])->save();
        $this->actingAs($user);

        Livewire::test(CreateTicket::class)
            ->fillForm([
                'unit_id' => $this->busdev->id,
                'problem_category_id' => $this->category->id,
            ])
            ->fillForm(['external_business_entity' => 'CS'])
            ->assertFormFieldDoesNotExist('externalAnswers.jabatan')
            // visible_if atas jabatan (otomatis: Manager) dan badan usaha (CS).
            ->assertFormFieldIsVisible('externalAnswers.approval_direksi')
            ->assertFormFieldDoesNotExist('externalAnswers.catatan_staff')
            ->assertFormFieldDoesNotExist('externalAnswers.badan_usaha')
            ->assertFormFieldIsVisible('externalAnswers.nomor_cs')
            ->fillForm(['external_business_entity' => 'LAIN'])
            ->assertFormFieldIsHidden('externalAnswers.nomor_cs')
            ->fillForm(['external_business_entity' => 'CS'])
            // "Finance" melanggar rules.max 5: input biasa berisi nilai awal yang bisa diubah.
            ->assertFormFieldExists('externalAnswers.divisi')
            ->assertFormSet(['answers.divisi' => 'Finance'])
            // Email atasan hanya dikirim bila perlu_approval = ya.
            ->assertSee('Diisi otomatis dari data karyawan')
            ->assertDontSee('Email atasan')
            ->fillForm(['answers.perlu_approval' => 'ya'])
            ->assertSee('Email atasan')
            ->fillForm([
                'title' => 'IK Penerimaan Barang',
                'answers.perlu_approval' => 'tidak',
                'answers.approval_direksi' => 'Disetujui',
                'answers.nomor_cs' => 'CS-1',
                'answers.divisi' => 'FIN',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $sent = $this->sentTicketFields()[0];
        $this->assertSame('Manager', $sent['answers[jabatan]']);
        $this->assertSame('Disetujui', $sent['answers[approval_direksi]']);
        $this->assertSame('CS-1', $sent['answers[nomor_cs]']);
        $this->assertSame('FIN', $sent['answers[divisi]']);
        $this->assertFalse($sent->has('answers[email_atasan]'));

        $undoRepeaterFake();
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
            ->assertSessionHas('ticket_success', fn (array $success): bool => $success['number'] === null
                && $success['title'] === 'IK Penerimaan Barang'
                && $success['unit'] === 'BUSDEV'
                && $success['external_ref'] === 'IK-20261125-AB3D');

        $this->assertSame(0, Ticket::query()->withTrashed()->count(), 'Pengajuan ke unit eksternal tidak membuat baris Ticket di Helpdesk.');
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

        $this->assertSame(0, Ticket::query()->withTrashed()->count());
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
            // Identitas pengguna yang login diisi otomatis: bukan input,
            // hanya disebut (beserta nilainya) di catatan baca-saja.
            ->assertFormFieldDoesNotExist('externalAnswers.nama')
            ->assertFormFieldDoesNotExist('externalAnswers.email')
            ->assertFormFieldExists('externalAnswers.jabatan')
            ->assertSee('Diisi otomatis dari data karyawan')
            ->assertSee('admin.finance@example.test')
            ->assertFormFieldIsHidden('description')
            ->assertFormFieldIsHidden('supporting_attachments')
            ->assertFormFieldIsHidden('priority_id')
            // Satu pertanyaan badan usaha: opsi dari form sistem tujuan.
            ->assertFormFieldIsHidden('business_entities_id')
            ->assertFormFieldIsVisible('external_business_entity')
            ->assertFormFieldDoesNotExist('externalAnswers.badan_usaha')
            ->fillForm([
                'title' => 'IK Penerimaan Barang',
                'external_business_entity' => 'CS',
                'answers.jenis' => 'revisi',
                'answers.nomor_lama' => 'IK-001',
                'answers.tujuan' => 'Standarisasi',
                'answers.proses' => [['judul' => 'Terima barang']],
            ])
            ->set('data.answers.dokumen', [UploadedFile::fake()->create('draft.pdf', 12, 'application/pdf')])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('Pengajuan diteruskan ke BUSDEV (IK-20261125-AB3D)');

        $this->assertSame(0, Ticket::query()->withTrashed()->count());
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
                && $fields['answers[email]'] === 'admin.finance@example.test'
                && $fields['answers[divisi]'] === 'Finance'
                && $fields['answers[badan_usaha]'] === 'CS'
                && $fields['answers[proses][0][pic_email]'] === 'admin.finance@example.test'
                && $fields['answers[jenis]'] === 'revisi'
                && $fields['answers[nomor_lama]'] === 'IK-001'
                && $fields['answers[proses][0][judul]'] === 'Terima barang'
                && $files === ['answers[dokumen][]=draft.pdf'];
        });

        $undoRepeaterFake();
    }

    public function test_panel_business_entity_question_uses_external_options_and_restores_the_internal_choice(): void
    {
        $undoRepeaterFake = Repeater::fake();
        Http::fake([
            self::BASE.'/forms/IK' => Http::response(['data' => $this->formDefinition()]),
            self::BASE.'/tickets' => Http::response(['data' => $this->snapshot('open', 'Diterima')], 201),
        ]);
        $other = BusinessEntity::query()->create(['name' => 'PT Mitra Lain']);
        $finance = Unit::query()->create(['name' => 'Finance']);
        $user = $this->unitUser('Admin Unit', $finance, 'Admin Finance');
        $user->forceFill(['business_entity_id' => $this->entity->id])->save();
        $this->actingAs($user);

        Livewire::test(CreateTicket::class)
            ->assertFormSet(['business_entities_id' => $this->entity->id])
            ->fillForm(['business_entities_id' => $other->id])
            ->fillForm(['unit_id' => $this->busdev->id])
            ->fillForm(['problem_category_id' => $this->category->id])
            // Pilihan awal: opsi yang cocok dengan badan usaha kontrak pengguna.
            ->assertFormSet(['external_business_entity' => 'CS'])
            ->assertFormFieldIsHidden('business_entities_id')
            ->assertFormFieldIsVisible('external_business_entity')
            // Kembali ke kategori internal: pilihan badan usaha helpdesk utuh.
            ->fillForm(['unit_id' => $this->it->id])
            ->fillForm(['problem_category_id' => $this->internalCategory->id])
            ->assertFormFieldIsVisible('business_entities_id')
            ->assertFormFieldIsHidden('external_business_entity')
            ->assertFormSet(['business_entities_id' => $other->id])
            ->fillForm(['unit_id' => $this->busdev->id])
            ->fillForm(['problem_category_id' => $this->category->id])
            ->assertFormSet(['external_business_entity' => 'CS'])
            ->fillForm([
                'title' => 'IK Penerimaan Barang',
                'external_business_entity' => 'LAIN',
                'answers.jenis' => 'baru',
                'answers.tujuan' => 'Standarisasi',
                'answers.proses' => [['judul' => 'Terima barang']],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('LAIN', $this->sentTicketFields()[0]['answers[badan_usaha]']);
        $this->assertSame(0, Ticket::query()->withTrashed()->count());

        $undoRepeaterFake();
    }

    public function test_panel_business_entity_question_validates_and_shows_external_errors(): void
    {
        $undoRepeaterFake = Repeater::fake();
        $definition = $this->formDefinition();
        $definition['fields'][1]['required'] = true;
        Http::fake([
            self::BASE.'/forms/IK' => Http::response(['data' => $definition]),
            self::BASE.'/tickets' => Http::response([
                'message' => 'The given data was invalid.',
                'errors' => ['answers.badan_usaha' => ['Badan usaha tidak aktif.']],
            ], 422),
        ]);
        $finance = Unit::query()->create(['name' => 'Finance']);
        $this->actingAs($this->unitUser('Admin Unit', $finance, 'Admin Finance'));

        $page = Livewire::test(CreateTicket::class)
            ->fillForm([
                'unit_id' => $this->busdev->id,
                'problem_category_id' => $this->category->id,
            ])
            // Pengguna tanpa badan usaha kontrak: tanpa pilihan awal, wajib dipilih.
            ->assertFormSet(['external_business_entity' => null])
            ->fillForm([
                'title' => 'IK Penerimaan Barang',
                'answers.jenis' => 'baru',
                'answers.tujuan' => 'Standarisasi',
                'answers.proses' => [['judul' => 'Terima barang']],
            ])
            ->call('create')
            ->assertHasFormErrors(['external_business_entity' => 'required']);
        Http::assertNotSent(fn (HttpRequest $request): bool => $request->url() === self::BASE.'/tickets');

        $page->fillForm(['external_business_entity' => 'PT SMI'])
            ->call('create')
            ->assertHasFormErrors(['external_business_entity']);
        Http::assertNotSent(fn (HttpRequest $request): bool => $request->url() === self::BASE.'/tickets');

        $page->fillForm(['external_business_entity' => 'CS'])
            ->call('create')
            ->assertHasFormErrors(['external_business_entity'])
            ->assertSee('Badan usaha tidak aktif.');
        $this->assertSame(0, Ticket::query()->count());

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
            self::BASE.'/tickets/IK-20261125-AB3D' => Http::response(['data' => $this->snapshot('done', 'Selesai', '2026-11-25T12:00:00+07:00')]),
        ]);
        $finance = Unit::query()->create(['name' => 'Finance']);
        $owner = $this->unitUser('Admin Unit', $finance, 'Admin Finance');
        $ticket = $this->legacyExternalTicket($owner, 'in_progress', 'Drafting');
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
        $finance = Unit::query()->create(['name' => 'Finance']);
        $owner = $this->unitUser('Admin Unit', $finance, 'Admin Finance');
        $ticket = $this->legacyExternalTicket($owner, 'in_progress', 'Drafting');
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
        ], ['proses' => ['x' => ['lampiran' => $file]]], $reporter);

        $this->assertSame(['jenis' => 'baru', 'proses' => [['judul' => 'A'], ['judul' => 'B']]], $answers);
        $this->assertSame(['proses' => [0 => ['lampiran' => $file]]], $files);

        [$answers] = $schemas->prepareAnswers($form, [
            'jenis' => 'revisi',
            'proses' => [['judul' => 'A', 'alasan' => 'Perubahan SOP']],
        ], [], $reporter);
        $this->assertSame('Perubahan SOP', $answers['proses'][0]['alasan']);

        $state = ['proses' => ['uuid-a' => ['judul' => 'A'], 'uuid-b' => ['judul' => 'B']]];
        $this->assertSame('answers.proses.uuid-b.judul', ExternalFormSchema::componentErrorPath($form, 'answers.proses.1.judul', $state));
        $this->assertSame('answers.proses.uuid-a.lampiran', ExternalFormSchema::componentErrorPath($form, 'answers.proses.0.lampiran.0', $state));
        $this->assertSame('answers', ExternalFormSchema::componentErrorPath($form, 'answers.tidak_ada', $state));
        $this->assertNull(ExternalFormSchema::componentErrorPath($form, 'title', $state));
    }

    public function test_schema_sanitizes_and_passes_through_wizard_steps(): void
    {
        $form = app(ExternalFormSchema::class)->sanitize([
            'key' => 'IK',
            'steps' => [
                ['key' => 'pemohon', 'title' => 'Data pemohon', 'help' => ' Isi data. ', 'sections' => [
                    ['key' => 'identitas', 'title' => 'Identitas', 'help' => 'Sesuai data karyawan.'],
                    ['key' => 'identitas', 'title' => 'Duplikat'],
                    ['key' => 'Bad Key', 'title' => 'Dibuang'],
                ]],
                ['key' => 'pemohon', 'title' => 'Duplikat'],
                ['key' => 'Bad', 'title' => 'Dibuang'],
                ['key' => 'dokumen', 'title' => 'Dokumen', 'category_picker' => true, 'sections' => [['key' => 'detail', 'title' => 'Detail dokumen']]],
                ['key' => 'observasi', 'category_picker' => true],
            ],
            'fields' => [
                ['key' => 'nama', 'type' => 'text', 'label' => 'Nama', 'step' => 'pemohon', 'section' => 'identitas'],
                ['key' => 'atasan', 'type' => 'text', 'label' => 'Atasan', 'step' => 'pemohon', 'section' => 'tidak_ada'],
                ['key' => 'tanpa_step', 'type' => 'text', 'label' => 'Tanpa langkah'],
                ['key' => 'step_asing', 'type' => 'text', 'label' => 'Langkah asing', 'step' => 'asing', 'section' => 'detail'],
                ['key' => 'proses', 'type' => 'repeater', 'label' => 'Proses', 'step' => 'dokumen', 'section' => 'detail', 'fields' => [
                    ['key' => 'judul', 'type' => 'text', 'label' => 'Judul', 'step' => 'pemohon', 'section' => 'identitas'],
                ]],
            ],
        ]);

        $this->assertSame([
            [
                'key' => 'pemohon',
                'title' => 'Data pemohon',
                'category_picker' => false,
                'sections' => [['key' => 'identitas', 'title' => 'Identitas', 'help' => 'Sesuai data karyawan.']],
                'help' => 'Isi data.',
            ],
            ['key' => 'dokumen', 'title' => 'Dokumen', 'category_picker' => true, 'sections' => [['key' => 'detail', 'title' => 'Detail dokumen']]],
            // Hanya satu category_picker.
            ['key' => 'observasi', 'title' => 'observasi', 'category_picker' => false, 'sections' => []],
        ], $form['steps']);

        $fields = collect($form['fields'])->keyBy('key');
        $this->assertSame(['pemohon', 'identitas'], [$fields['nama']['step'], $fields['nama']['section']]);
        // Seksi tak dikenal: tanpa seksi; tanpa/dengan langkah tak dikenal: langkah terakhir.
        $this->assertSame('pemohon', $fields['atasan']['step']);
        $this->assertArrayNotHasKey('section', $fields['atasan']);
        $this->assertSame('observasi', $fields['tanpa_step']['step']);
        $this->assertSame('observasi', $fields['step_asing']['step']);
        $this->assertArrayNotHasKey('section', $fields['step_asing']);
        $this->assertSame(['dokumen', 'detail'], [$fields['proses']['step'], $fields['proses']['section']]);
        // step/section hanya untuk field level atas.
        $this->assertArrayNotHasKey('step', $fields['proses']['fields'][0]);
        $this->assertArrayNotHasKey('section', $fields['proses']['fields'][0]);
        $this->assertTrue(ExternalFormSchema::hasSteps($form));
        $this->assertTrue(ExternalFormSchema::hasCategoryPicker($form));

        // Tanpa steps: satu halaman seperti biasa, field tanpa step/section.
        $plain = app(ExternalFormSchema::class)->sanitize([
            'key' => 'IK',
            'fields' => [['key' => 'nama', 'type' => 'text', 'label' => 'Nama', 'step' => 'pemohon', 'section' => 'identitas']],
        ]);
        $this->assertSame([], $plain['steps']);
        $this->assertSame(['key' => 'nama', 'type' => 'text', 'label' => 'Nama', 'required' => false], $plain['fields'][0]);
        $this->assertFalse(ExternalFormSchema::hasSteps($plain));
    }

    public function test_unit_form_endpoint_returns_the_opening_wizard_form_with_category_cards(): void
    {
        $sop = $this->wizardCategories();
        Http::fake([
            self::BASE.'/forms/IK' => Http::response(['data' => $this->wizardFormDefinition('IK')]),
            self::BASE.'/forms/SOP' => Http::response(['data' => $this->wizardFormDefinition('SOP')]),
            self::BASE.'/categories' => Http::response(['data' => [
                ['key' => 'SOP', 'name' => 'SOP', 'active' => true, 'description' => 'Prosedur kerja lintas fungsi.'],
                ['key' => 'IK', 'name' => 'IK', 'active' => true, 'description' => 'Langkah kerja satu tugas.'],
                ['key' => 'JD', 'name' => 'Jobdesk', 'active' => false],
            ]]),
        ]);

        $this->getJson(route('public-tickets.external-unit-form', $this->busdev))->assertStatus(401);
        Http::assertNothingSent();

        $reporter = $this->talentaReporter();
        $this->rememberReporter($reporter, str_repeat('W', 64), str_repeat('S', 40));

        $response = $this->getJson(route('public-tickets.external-unit-form', $this->busdev))
            ->assertOk()
            // Form pembuka = kategori terpetakan pertama (urut nama).
            ->assertJsonPath('category_id', $this->category->id)
            ->assertJsonPath('data.key', 'IK')
            ->assertJsonPath('data.steps.0.key', 'pemohon')
            ->assertJsonPath('data.steps.0.sections.0.title', 'Identitas')
            ->assertJsonPath('data.steps.1.category_picker', true)
            ->assertJsonPath('data.fields.0.step', 'pemohon')
            ->assertJsonPath('data.fields.0.section', 'identitas')
            ->assertJsonPath('data.fields.0.auto', true)
            ->assertJsonPath('data.business_entity.key', 'badan_usaha')
            // Kartu kategori: urutan dan deskripsi sistem tujuan, nama & kode helpdesk; JD nonaktif.
            ->assertJsonPath('data.categories', [
                ['id' => $sop->id, 'code' => 'SOP', 'name' => 'Standard Operating Procedure', 'description' => 'Prosedur kerja lintas fungsi.', 'needs_observation' => false],
                ['id' => $this->category->id, 'code' => 'IK', 'name' => 'Instruksi Kerja (IK)', 'description' => 'Langkah kerja satu tugas.', 'needs_observation' => false],
            ]);

        $body = (string) $response->getContent();
        foreach (['Rina Pelapor', 'rina@complete.test', 'Marketing Area', self::TOKEN] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }

        // Form kategori juga membawa kartu kategori (untuk mengganti pilihan).
        $this->getJson(route('public-tickets.external-form', $sop))
            ->assertOk()
            ->assertJsonPath('data.key', 'SOP')
            ->assertJsonCount(2, 'data.categories');

        $this->getJson(route('public-tickets.external-unit-form', $this->it))->assertNotFound();

        // Daftar kategori dan form di-cache.
        $this->getJson(route('public-tickets.external-unit-form', $this->busdev))->assertOk();
        Http::assertSentCount(3);
    }

    public function test_unit_form_completes_a_public_reporter_from_talenta_before_prefilling(): void
    {
        $this->wizardCategories();
        Http::fake([
            self::BASE.'/forms/IK' => Http::response(['data' => $this->wizardFormDefinition('IK')]),
            self::BASE.'/forms/SOP' => Http::response(['data' => $this->wizardFormDefinition('SOP')]),
            self::BASE.'/categories' => Http::response(['data' => [['key' => 'IK', 'name' => 'IK', 'active' => true]]]),
        ]);
        $file = tempnam(sys_get_temp_dir(), 'talenta-');
        file_put_contents($file, json_encode(['data' => ['data' => [[
            'id_employee' => '2024.09.09.01',
            'first_name' => 'RINA',
            'last_name' => 'TALENTA',
            'email' => 'rina.talenta@complete.test',
            'mobile_phone' => '081234500777',
            'branch' => 'Complete Selular',
            'organization' => 'IT',
            'job' => 'STAFF IT',
            'title' => 'STAFF',
        ]]]]));
        config()->set('services.talenta.employee_file', $file);

        // Pelapor dari form publik: hanya nomor HP, belum disinkron dari Talenta.
        $reporter = $this->publicReporter();
        $this->rememberReporter($reporter, str_repeat('T', 64), str_repeat('S', 40));

        try {
            $response = $this->getJson(route('public-tickets.external-unit-form', $this->busdev))
                ->assertOk()
                ->assertJsonPath('data.fields.0.key', 'nama')
                ->assertJsonPath('data.fields.0.auto', true)
                ->assertJsonPath('data.fields.3.key', 'email')
                ->assertJsonPath('data.fields.3.auto', true);
        } finally {
            @unlink($file);
        }

        $reporter->refresh();
        $this->assertSame('2024.09.09.01', $reporter->identity);
        $this->assertSame('rina.talenta@complete.test', $reporter->email);
        $this->assertSame($this->it->id, (int) $reporter->unit_id);
        // Nilai identitas tetap hanya di server.
        foreach (['TALENTA', 'rina.talenta@complete.test', '2024.09.09.01'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $response->getContent());
        }
    }

    public function test_new_reporter_types_their_own_name_instead_of_the_placeholder(): void
    {
        Http::fake([
            self::BASE.'/forms/IK' => Http::response(['data' => $this->formDefinition()]),
            self::BASE.'/tickets' => Http::response(['data' => $this->snapshot('open', 'Diterima')], 201),
        ]);
        // Nomor yang tidak ada di Talenta maupun helpdesk: akun dibuat dengan nama sementara.
        $result = app(HelpdeskReporterRegistrationService::class)->resolveOrCreateFromSubmittedPhone('081234500666');
        $reporter = $result['user'];
        $this->assertSame(HelpdeskReporterName::placeholder('6281234500666'), $reporter->name);
        $this->assertTrue(HelpdeskReporterName::isPlaceholder($reporter));
        $this->rememberReporter($reporter, str_repeat('N', 64), str_repeat('S', 40));

        // Field nama tidak otomatis: pelapor mengisinya sendiri.
        $this->getJson(route('public-tickets.external-form', $this->category))
            ->assertOk()
            ->assertJsonPath('data.fields.0.key', 'nama')
            ->assertJsonMissingPath('data.fields.0.auto');

        $payload = $this->externalPayload(str_repeat('S', 40));
        $payload['answers']['nama'] = 'Sari Pemohon Baru';
        $this->post(route('public-tickets.store'), $payload)->assertSessionHasNoErrors();

        $fields = $this->sentTicketFields()[0];
        $this->assertSame('Sari Pemohon Baru', $fields['answers[nama]']);
        // Nama sementara tidak dikirim sebagai nama pelapor dan ikut diperbarui di akun helpdesk.
        $this->assertSame('Sari Pemohon Baru', $fields['reporter[name]']);
        $this->assertSame('Sari Pemohon Baru', $reporter->fresh()->name);
    }

    public function test_successful_public_submission_shows_external_ref_and_track_url_without_helpdesk_ticket(): void
    {
        $track = 'https://od.test/permintaan-dokumen/lacak?kode=IK-20261125-AB3D';
        Http::fake([
            self::BASE.'/forms/IK' => Http::response(['data' => $this->formDefinition()]),
            self::BASE.'/tickets' => Http::sequence()
                ->push(['data' => ['track_url' => $track] + $this->snapshot('open', 'Diterima')], 201)
                ->push(['data' => ['track_url' => 'https://jahat.test/lacak', 'ref' => 'IK-20261125-AB3E'] + $this->snapshot('open', 'Diterima')], 201)
                ->push(['data' => ['track_url' => 'javascript:alert(1)', 'ref' => 'IK-20261125-AB3F'] + $this->snapshot('open', 'Diterima')], 201),
        ]);
        $reporter = $this->publicReporter();
        $this->rememberReporter($reporter, str_repeat('L', 64), str_repeat('S', 40));

        // Tetap di Helpdesk dengan konfirmasi sukses berisi external_ref + track_url tanpa tiket Helpdesk.
        $this->post(route('public-tickets.store'), $this->externalPayload(str_repeat('S', 40)))
            ->assertRedirect(route('public-tickets.create'))
            ->assertSessionHas('ticket_success', fn (array $success): bool => $success['number'] === null
                && ($success['external_ref'] ?? null) === 'IK-20261125-AB3D'
                && ($success['track_url'] ?? null) === $track);
        $this->assertSame(0, Ticket::query()->withTrashed()->count());
        Notification::assertNothingSent();

        // Host lain atau skema selain http(s): tidak membawa track_url eksternal.
        foreach (['form-fill-002', 'form-fill-003'] as $index => $key) {
            $token = str_repeat((string) ($index + 1), 40);
            $this->rememberReporter($reporter, str_repeat('M', 63).$index, $token);
            $payload = $this->externalPayload($token);
            $payload['idempotency_key'] = $key;
            $this->post(route('public-tickets.store'), $payload)
                ->assertRedirect(route('public-tickets.create'))
                ->assertSessionHas('ticket_success', fn (array $success): bool => ($success['track_url'] ?? null) === null);
        }
    }

    public function test_unit_form_endpoint_without_steps_reports_no_steps(): void
    {
        Http::fake([self::BASE.'/forms/IK' => Http::response(['data' => $this->formDefinition()])]);
        $this->rememberReporter($this->publicReporter(), str_repeat('X', 64), str_repeat('S', 40));

        $this->getJson(route('public-tickets.external-unit-form', $this->busdev))
            ->assertOk()
            ->assertJsonPath('data.steps', [])
            ->assertJsonMissingPath('data.categories');

        // Tanpa category_picker, daftar kategori tidak diambil.
        Http::assertSentCount(1);
    }

    public function test_wizard_ticket_title_is_derived_from_the_answers(): void
    {
        $form = app(ExternalFormSchema::class)->sanitize($this->wizardFormDefinition('IK'));
        $sop = $this->wizardCategories();

        $this->assertSame(
            'IK · Terima barang',
            ExternalFormSchema::deriveTitle($form, ['atasan' => 'Budi', 'proses' => [['judul' => '  Terima barang '], ['judul' => 'Lain']]], $this->category),
        );
        // Baris pertama tanpa judul: field teks wajib pertama (bukan isian otomatis).
        $form['fields'][] = ['key' => 'perihal', 'type' => 'text', 'label' => 'Perihal', 'required' => true, 'step' => 'dokumen'];
        $this->assertSame(
            'SOP · Rapat bulanan',
            ExternalFormSchema::deriveTitle($form, ['nama' => 'Rina', 'perihal' => 'Rapat bulanan', 'proses' => [['judul' => '']]], $sop),
        );
        // Tidak ada teks: nama kategori.
        $this->assertSame('Standard Operating Procedure', ExternalFormSchema::deriveTitle($form, ['proses' => []], $sop));
        // Dipotong ke batas kolom judul.
        $long = ExternalFormSchema::deriveTitle($form, ['proses' => [['judul' => str_repeat('a', 400)]]], $sop);
        $this->assertSame(ExternalFormSchema::TITLE_MAX_LENGTH, mb_strlen($long));
        $this->assertStringStartsWith('SOP · aaa', $long);
    }

    public function test_public_wizard_submit_derives_the_title_and_forwards_answers(): void
    {
        $this->wizardCategories();
        Http::fake([
            self::BASE.'/forms/IK' => Http::response(['data' => $this->wizardFormDefinition('IK')]),
            self::BASE.'/tickets' => Http::response(['data' => $this->snapshot('open', 'Diterima')], 201),
        ]);
        $reporter = $this->talentaReporter();
        $this->rememberReporter($reporter, str_repeat('Y', 64), str_repeat('S', 40));

        // Payload wizard: tanpa judul; badan usaha dijawab di seksi identitas.
        $this->post(route('public-tickets.store'), [
            'submission_token' => str_repeat('S', 40),
            'website' => '',
            'external_business_entity' => 'CS',
            'unit_id' => $this->busdev->id,
            'problem_category_id' => $this->category->id,
            'idempotency_key' => 'wizard-fill-001',
            'answers' => [
                'nama' => 'Nama Palsu',
                'atasan' => 'Budi',
                'proses' => [['judul' => 'Terima barang', 'tujuan' => 'Standarisasi alur']],
                'obs_tanggal_1' => now()->addDay()->toDateString(),
            ],
        ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('ticket_success', fn (array $success): bool => $success['number'] === null
                && $success['title'] === 'IK · Terima barang');

        $this->assertSame(0, Ticket::query()->withTrashed()->count());

        $sent = $this->sentTicketFields()[0];
        $this->assertSame('IK · Terima barang', $sent['title']);
        $this->assertSame(hash('sha256', 'public:'.$reporter->id.':wizard-fill-001'), $sent['idempotency_key']);
        // Isian otomatis diisi server, bukan dari browser.
        $this->assertSame('Rina Pelapor', $sent['answers[nama]']);
        $this->assertSame('rina@complete.test', $sent['answers[email]']);
        $this->assertSame('CS', $sent['answers[badan_usaha]']);
        $this->assertSame('Budi', $sent['answers[atasan]']);
        $this->assertSame('Terima barang', $sent['answers[proses][0][judul]']);
    }

    public function test_public_wizard_submit_maps_external_errors_to_answer_keys(): void
    {
        $this->wizardCategories();
        Http::fake([
            self::BASE.'/forms/IK' => Http::response(['data' => $this->wizardFormDefinition('IK')]),
            self::BASE.'/tickets' => Http::response([
                'message' => 'The given data was invalid.',
                'errors' => [
                    'answers.badan_usaha' => ['Badan usaha tidak dikenal.'],
                    'answers.proses.0.judul' => ['Judul wajib diisi.'],
                ],
            ], 422),
        ]);
        $this->rememberReporter($this->talentaReporter(), str_repeat('Z', 64), str_repeat('S', 40));

        $this->from(route('public-tickets.create'))
            ->post(route('public-tickets.store'), [
                'submission_token' => str_repeat('S', 40),
                'external_business_entity' => 'CS',
                'unit_id' => $this->busdev->id,
                'problem_category_id' => $this->category->id,
                'idempotency_key' => 'wizard-fill-002',
                'answers' => ['proses' => [['judul' => 'X', 'tujuan' => 'Y']], 'obs_tanggal_1' => now()->addDay()->toDateString()],
            ])
            ->assertSessionHasErrors([
                'external_business_entity' => 'Badan usaha tidak dikenal.',
                'answers.proses.0.judul' => 'Judul wajib diisi.',
            ]);

        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_public_single_page_external_form_still_requires_a_title(): void
    {
        Http::fake([self::BASE.'/forms/IK' => Http::response(['data' => $this->formDefinition()])]);
        $this->rememberReporter($this->publicReporter(), str_repeat('V', 64), str_repeat('S', 40));
        $payload = $this->externalPayload(str_repeat('S', 40));
        $payload['title'] = '';

        $this->from(route('public-tickets.create'))
            ->post(route('public-tickets.store'), $payload)
            ->assertSessionHasErrors(['title' => 'Judul laporan wajib diisi.']);

        $this->assertDatabaseCount('tickets', 0);
        Http::assertNotSent(fn (HttpRequest $request): bool => $request->url() === self::BASE.'/tickets');
    }

    public function test_panel_wizard_create_follows_the_external_steps(): void
    {
        $undoRepeaterFake = Repeater::fake();
        $sop = $this->wizardCategories();
        Http::fake([
            self::BASE.'/forms/IK' => Http::response(['data' => $this->wizardFormDefinition('IK')]),
            self::BASE.'/forms/SOP' => Http::response(['data' => $this->wizardFormDefinition('SOP')]),
            self::BASE.'/categories' => Http::response(['data' => [
                ['key' => 'IK', 'name' => 'IK', 'active' => true, 'description' => 'Langkah kerja satu tugas.'],
                ['key' => 'SOP', 'name' => 'SOP', 'active' => true, 'description' => 'Prosedur kerja lintas fungsi.'],
            ]]),
            self::BASE.'/tickets' => Http::response(['data' => $this->snapshot('open', 'Diterima')], 201),
        ]);
        $finance = Unit::query()->create(['name' => 'Finance']);
        $user = $this->unitUser('Admin Unit', $finance, 'Admin Finance');
        $user->forceFill(['business_entity_id' => $this->entity->id])->save();
        $this->actingAs($user);

        $page = Livewire::test(CreateTicket::class)
            ->fillForm(['unit_id' => $this->busdev->id])
            // Wizard sistem tujuan: kategori, judul, dan isian lama tidak dipakai.
            ->assertFormFieldIsHidden('problem_category_id')
            ->assertFormFieldIsHidden('title')
            ->assertFormFieldIsHidden('priority_id')
            ->assertFormFieldIsHidden('description')
            ->assertFormFieldIsHidden('business_entities_id')
            ->assertFormFieldIsHidden('external_business_entity')
            ->assertSee('Data pemohon')
            ->assertSee('Identitas')
            ->assertSee('Langkah kerja satu tugas.')
            ->assertSee('Kirim pengajuan')
            // Isian otomatis (termasuk dari seksi auto-only yang disembunyikan) disebut di langkahnya.
            ->assertSee('Diisi otomatis dari data karyawan')
            ->assertSee('admin.finance@example.test')
            // Pilihan awal badan usaha: opsi yang cocok dengan badan usaha kontrak.
            ->assertFormSet(['external_business_entity' => 'CS'])
            ->fillForm(['problem_category_id' => $sop->id])
            ->fillForm(['answers.atasan' => 'Budi'])
            ->fillForm(['problem_category_id' => $this->category->id])
            // Isian berkey sama dipertahankan saat kategori berganti.
            ->assertFormSet(['answers.atasan' => 'Budi', 'external_business_entity' => 'CS'])
            ->assertSee('Apa itu Instruksi Kerja (IK)?')
            ->assertSee('Jadwal observasi')
            ->fillForm([
                'answers.proses' => [['judul' => 'Terima barang', 'tujuan' => 'Standarisasi alur']],
                'answers.obs_tanggal_1' => now()->addDay()->toDateString(),
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('Pengajuan diteruskan ke BUSDEV (IK-20261125-AB3D)');

        $this->assertSame(0, Ticket::query()->withTrashed()->count());

        $sent = $this->sentTicketFields()[0];
        $this->assertSame('IK · Terima barang', $sent['title']);
        $this->assertSame('IK', $sent['category_key']);
        $this->assertSame('Admin Finance', $sent['answers[nama]']);
        $this->assertSame('CS', $sent['answers[badan_usaha]']);
        $this->assertSame('Budi', $sent['answers[atasan]']);
        $this->assertSame('Terima barang', $sent['answers[proses][0][judul]']);
        $this->assertSame(hash('sha256', 'panel:'.$user->id.':'.$page->get('externalIdempotencyKey')), $sent['idempotency_key']);

        $undoRepeaterFake();
    }

    public function test_panel_wizard_hides_the_observation_step_for_categories_without_it_and_requires_a_category(): void
    {
        $undoRepeaterFake = Repeater::fake();
        $sop = $this->wizardCategories();
        Http::fake([
            self::BASE.'/forms/IK' => Http::response(['data' => $this->wizardFormDefinition('IK')]),
            self::BASE.'/forms/SOP' => Http::response(['data' => $this->wizardFormDefinition('SOP')]),
            self::BASE.'/categories' => Http::response(['data' => []], 500),
            self::BASE.'/tickets' => Http::response(['data' => $this->snapshot('open', 'Diterima')], 201),
        ]);
        $finance = Unit::query()->create(['name' => 'Finance']);
        $this->actingAs($this->unitUser('Admin Unit', $finance, 'Admin Finance'));

        Livewire::test(CreateTicket::class)
            ->fillForm(['unit_id' => $this->busdev->id])
            // Sebelum kategori dipilih langkah sesudah pilihan kategori belum tampil.
            ->assertDontSee('Jadwal observasi')
            ->fillForm(['external_business_entity' => 'CS'])
            ->call('create')
            ->assertHasFormErrors(['problem_category_id' => 'required'])
            ->fillForm(['problem_category_id' => $sop->id])
            ->assertDontSee('Jadwal observasi')
            ->fillForm(['answers.proses' => [['judul' => 'Rapat bulanan', 'tujuan' => 'Alur rapat']]])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(0, Ticket::query()->withTrashed()->count());
        $this->assertSame('SOP · Rapat bulanan', $this->sentTicketFields()[0]['title']);
        $this->assertArrayNotHasKey('answers[obs_tanggal_1]', $this->sentTicketFields()[0]->all());

        $undoRepeaterFake();
    }

    public function test_category_cards_carry_the_observation_note_from_the_external_categories(): void
    {
        $undoRepeaterFake = Repeater::fake();
        $sop = $this->wizardCategories();
        Http::fake([
            self::BASE.'/forms/IK' => Http::response(['data' => $this->wizardFormDefinition('IK')]),
            self::BASE.'/forms/SOP' => Http::response(['data' => $this->wizardFormDefinition('SOP')]),
            self::BASE.'/categories' => Http::response(['data' => [
                ['key' => 'IK', 'name' => 'IK', 'active' => true, 'description' => 'Langkah kerja satu tugas.', 'needs_observation' => true, 'has_template' => true],
                ['key' => 'SOP', 'name' => 'SOP', 'active' => true, 'description' => 'Prosedur kerja lintas fungsi.', 'needs_observation' => false],
            ]]),
        ]);
        $this->rememberReporter($this->talentaReporter(), str_repeat('N', 64), str_repeat('S', 40));

        $this->getJson(route('public-tickets.external-unit-form', $this->busdev))
            ->assertOk()
            ->assertJsonPath('data.categories.0.needs_observation', true)
            ->assertJsonPath('data.categories.1.needs_observation', false);

        $finance = Unit::query()->create(['name' => 'Finance']);
        $this->actingAs($this->unitUser('Admin Unit', $finance, 'Admin Finance'));

        Livewire::test(CreateTicket::class)
            ->fillForm(['unit_id' => $this->busdev->id, 'problem_category_id' => $sop->id])
            ->assertDontSee('Termasuk observasi langsung')
            ->fillForm(['problem_category_id' => $this->category->id])
            ->assertSee('Apa itu Instruksi Kerja (IK)?')
            ->assertSee('Termasuk observasi langsung oleh tim BUSDEV.');

        $undoRepeaterFake();
    }

    public function test_panel_wizard_unit_calls_an_unavailable_target_once_instead_of_on_every_check(): void
    {
        $this->wizardCategories();
        Http::fake([
            self::BASE.'/forms/*' => Http::response(['message' => 'down'], 503),
            self::BASE.'/categories' => Http::response(['message' => 'down'], 503),
        ]);
        $finance = Unit::query()->create(['name' => 'Finance']);
        $this->actingAs($this->unitUser('Admin Unit', $finance, 'Admin Finance'));

        $page = Livewire::test(CreateTicket::class)
            ->fillForm(['unit_id' => $this->busdev->id])
            // Form pembuka gagal dimuat: alur satu halaman.
            ->assertFormFieldIsVisible('problem_category_id')
            ->assertFormFieldIsVisible('title');

        // Banyak closure hidden()/visible() dalam satu render: satu percobaan saja.
        $formCalls = fn (): int => Http::recorded(fn (HttpRequest $request): bool => str_contains($request->url(), '/forms/'))->count();
        $this->assertSame(1, $formCalls());

        // Round-trip berikutnya tidak menunggu sistem tujuan lagi selama kegagalan diingat.
        $page->fillForm(['title' => 'Coba lagi']);
        $this->assertSame(1, $formCalls());
    }

    public function test_panel_wizard_unit_walks_missing_categories_once_per_request(): void
    {
        $this->wizardCategories();
        Http::fake([self::BASE.'/forms/*' => Http::response(['message' => 'Not found'], 404)]);
        $finance = Unit::query()->create(['name' => 'Finance']);
        $this->actingAs($this->unitUser('Admin Unit', $finance, 'Admin Finance'));

        Livewire::test(CreateTicket::class)->fillForm(['unit_id' => $this->busdev->id]);

        // IK dan SOP masing-masing sekali, bukan sekali per closure.
        $this->assertSame(2, Http::recorded(fn (HttpRequest $request): bool => str_contains($request->url(), '/forms/'))->count());
    }

    public function test_public_wizard_unit_accepts_a_category_whose_form_has_no_steps(): void
    {
        $sop = $this->wizardCategories();
        Http::fake([
            self::BASE.'/forms/IK' => Http::response(['data' => $this->wizardFormDefinition('IK')]),
            self::BASE.'/forms/SOP' => Http::response(['data' => $this->singlePageWizardCategory('SOP')]),
            self::BASE.'/tickets' => Http::response(['data' => $this->snapshot('open', 'Diterima')], 201),
        ]);
        $this->rememberReporter($this->talentaReporter(), str_repeat('P', 64), str_repeat('S', 40));

        // Wizard tidak menanyakan judul: judul diturunkan walau form SOP tanpa steps.
        $this->post(route('public-tickets.store'), [
            'submission_token' => str_repeat('S', 40),
            'website' => '',
            'external_business_entity' => 'CS',
            'unit_id' => $this->busdev->id,
            'problem_category_id' => $sop->id,
            'idempotency_key' => 'wizard-fill-sop',
            'answers' => ['atasan' => 'Budi', 'proses' => [['judul' => 'Rapat bulanan', 'tujuan' => 'Alur rapat']]],
        ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('ticket_success', fn (array $success): bool => $success['title'] === 'SOP · Rapat bulanan');

        $this->assertSame(0, Ticket::query()->withTrashed()->count());
        $this->assertSame('SOP · Rapat bulanan', $this->sentTicketFields()[0]['title']);
    }

    public function test_panel_wizard_unit_renders_and_submits_a_category_whose_form_has_no_steps(): void
    {
        $undoRepeaterFake = Repeater::fake();
        $sop = $this->wizardCategories();
        Http::fake([
            self::BASE.'/forms/IK' => Http::response(['data' => $this->wizardFormDefinition('IK')]),
            self::BASE.'/forms/SOP' => Http::response(['data' => $this->singlePageWizardCategory('SOP')]),
            self::BASE.'/categories' => Http::response(['data' => []], 500),
            self::BASE.'/tickets' => Http::response(['data' => $this->snapshot('open', 'Diterima')], 201),
        ]);
        $finance = Unit::query()->create(['name' => 'Finance']);
        $this->actingAs($this->unitUser('Admin Unit', $finance, 'Admin Finance'));

        Livewire::test(CreateTicket::class)
            ->fillForm(['unit_id' => $this->busdev->id])
            ->fillForm(['external_business_entity' => 'CS', 'problem_category_id' => $sop->id])
            ->assertFormFieldIsHidden('title')
            ->assertSee('Data pemohon')
            ->fillForm(['answers.proses' => [['judul' => 'Rapat bulanan', 'tujuan' => 'Alur rapat']]])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(0, Ticket::query()->withTrashed()->count());
        $this->assertSame('SOP · Rapat bulanan', $this->sentTicketFields()[0]['title']);
        $this->assertSame('Rapat bulanan', $this->sentTicketFields()[0]['answers[proses][0][judul]']);

        $undoRepeaterFake();
    }

    public function test_smart_business_entity_and_department_matching(): void
    {
        $options = [
            ['value' => 'CV CS', 'label' => 'CV CS'],
            ['value' => 'PT CSN', 'label' => 'PT CSN'],
            ['value' => 'PT SMI', 'label' => 'PT SMI'],
        ];
        // "Complete Selular" (this->entity) cocok dengan "CV CS" secara unik lewat akronim.
        $this->assertSame('CV CS', ExternalFormSchema::matchBusinessEntityOption($options, 'Complete Selular'));
        $this->assertSame($this->entity->id, ExternalFormSchema::matchingBusinessEntityId(['value' => 'CV CS', 'label' => 'CV CS']));
        $this->assertNull(ExternalFormSchema::matchingBusinessEntityId(['value' => 'PT LAIN', 'label' => 'PT LAIN']));

        $deptField = [
            'key' => 'departemen',
            'type' => 'select',
            'options' => [
                ['value' => 'Management Information System', 'label' => 'Management Information System'],
                ['value' => 'Human Capital Management', 'label' => 'Human Capital Management'],
            ],
        ];
        $this->assertSame('Management Information System', ExternalFormSchema::matchValue($deptField, 'IT'));
        $this->assertSame('Human Capital Management', ExternalFormSchema::matchValue($deptField, 'HR'));
        $this->assertNull(ExternalFormSchema::matchValue($deptField, 'Divisi Tidak Ada'));
    }

    public function test_panel_wizard_dispatches_step_jump_on_422_validation_error(): void
    {
        $undoRepeaterFake = Repeater::fake();
        $this->wizardCategories();
        Http::fake([
            self::BASE.'/forms/IK' => Http::response(['data' => $this->wizardFormDefinition('IK')]),
            self::BASE.'/categories' => Http::response(['data' => [['key' => 'IK', 'name' => 'IK', 'active' => true]]]),
            self::BASE.'/tickets' => Http::response([
                'message' => 'Validation error',
                'errors' => ['answers.proses.0.judul' => ['Judul dokumen ditolak sistem tujuan.']],
            ], 422),
        ]);
        $finance = Unit::query()->create(['name' => 'Finance']);
        $this->actingAs($this->unitUser('Admin Unit', $finance, 'Admin Finance'));

        Livewire::test(CreateTicket::class)
            ->fillForm(['unit_id' => $this->busdev->id])
            ->fillForm([
                'external_business_entity' => 'CS',
                'problem_category_id' => $this->category->id,
                'answers.proses' => [['judul' => 'Terima barang', 'tujuan' => 'Standarisasi alur']],
                'answers.obs_tanggal_1' => now()->addDay()->toDateString(),
            ])
            ->call('create')
            ->assertDispatched('go-to-wizard-step')
            ->assertHasFormErrors(['answers.proses.0.judul']);

        $undoRepeaterFake();
    }

    /**
     * Form kategori unit wizard yang belum memakai `steps` (v1): field sama,
     * tanpa step/section.
     *
     * @return array<string, mixed>
     */
    private function singlePageWizardCategory(string $key): array
    {
        $form = $this->wizardFormDefinition($key);
        unset($form['steps']);
        $form['fields'] = array_map(function (array $field): array {
            unset($field['step'], $field['section']);

            return $field;
        }, $form['fields']);

        return $form;
    }

    /**
     * Kategori kedua unit berintegrasi (SOP) untuk form wizard.
     */
    private function wizardCategories(): ProblemCategory
    {
        return ProblemCategory::query()->create([
            'unit_id' => $this->busdev->id,
            'name' => 'Standard Operating Procedure',
            'external_key' => 'SOP',
        ]);
    }

    /**
     * Form wizard seperti form Permintaan Dokumen: Data pemohon → Dokumen
     * (pilihan kategori) → Jadwal observasi (hanya IK).
     *
     * @return array<string, mixed>
     */
    private function wizardFormDefinition(string $key): array
    {
        $fields = [
            ['key' => 'nama', 'type' => 'text', 'label' => 'Nama lengkap', 'required' => true, 'prefill' => 'reporter.name', 'step' => 'pemohon', 'section' => 'identitas'],
            [
                'key' => 'badan_usaha',
                'type' => 'select',
                'label' => 'Badan usaha',
                'required' => true,
                'prefill' => 'ticket.business_entity',
                'options' => [['value' => 'CS', 'label' => 'Complete Selular'], ['value' => 'LAIN', 'label' => 'Lainnya']],
                'step' => 'pemohon',
                'section' => 'identitas',
            ],
            ['key' => 'atasan', 'type' => 'text', 'label' => 'Atasan langsung', 'required' => false, 'step' => 'pemohon', 'section' => 'identitas'],
            ['key' => 'email', 'type' => 'email', 'label' => 'Email perusahaan', 'required' => true, 'prefill' => 'reporter.email', 'step' => 'pemohon', 'section' => 'kontak'],
            [
                'key' => 'proses',
                'type' => 'repeater',
                'label' => 'Dokumen yang diajukan',
                'required' => true,
                'min_items' => 1,
                'max_items' => 5,
                'step' => 'dokumen',
                'section' => 'detail',
                'fields' => [
                    ['key' => 'judul', 'type' => 'text', 'label' => 'Judul dokumen', 'required' => true],
                    ['key' => 'tujuan', 'type' => 'textarea', 'label' => 'Uraian', 'required' => true],
                ],
            ],
        ];

        if ($key === 'IK') {
            $fields[] = ['key' => 'obs_tanggal_1', 'type' => 'date', 'label' => 'Tanggal observasi pilihan 1', 'required' => true, 'rules' => ['min_date' => 'today'], 'step' => 'observasi', 'section' => 'jadwal'];
        }

        return [
            'key' => $key,
            'title' => $key === 'IK' ? 'Instruksi Kerja (IK)' : 'Standard Operating Procedure (SOP)',
            'description' => 'Deskripsi '.$key.'.',
            'downloads' => [['label' => 'Contoh '.$key, 'url' => 'https://od.test/files/contoh-'.strtolower($key).'.pdf']],
            'steps' => [
                ['key' => 'pemohon', 'title' => 'Data pemohon', 'sections' => [
                    ['key' => 'identitas', 'title' => 'Identitas', 'help' => 'Isi sesuai data karyawan.'],
                    ['key' => 'kontak', 'title' => 'Kontak', 'help' => 'Kami menghubungi Anda lewat kontak ini.'],
                ]],
                ['key' => 'dokumen', 'title' => 'Dokumen', 'category_picker' => true, 'sections' => [
                    ['key' => 'detail', 'title' => 'Detail dokumen'],
                ]],
                ['key' => 'observasi', 'title' => 'Jadwal observasi', 'sections' => [
                    ['key' => 'jadwal', 'title' => 'Jadwal observasi', 'help' => 'Tim OD akan mengamati langsung.'],
                ]],
            ],
            'fields' => $fields,
        ];
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
            'external_business_entity' => 'CS',
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
                    // Badan usaha yang dipilih di form helpdesk.
                    'prefill' => 'ticket.business_entity',
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
                        ['key' => 'pic_email', 'type' => 'email', 'label' => 'Email PIC', 'required' => false, 'prefill' => 'reporter.email'],
                        ['key' => 'lampiran', 'type' => 'file', 'label' => 'Lampiran', 'required' => false, 'rules' => ['accept' => ['pdf'], 'max_kb' => 2048]],
                    ],
                ],
                ['key' => 'dokumen', 'type' => 'files', 'label' => 'Dokumen', 'required' => false, 'rules' => ['max_files' => 3]],
                // Identitas karyawan (Talenta): tampil hanya bila helpdesk tidak punya nilainya.
                ['key' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => false, 'prefill' => 'reporter.email'],
                ['key' => 'divisi', 'type' => 'text', 'label' => 'Divisi', 'required' => false, 'prefill' => 'reporter.unit'],
                [
                    'key' => 'jabatan',
                    'type' => 'select',
                    'label' => 'Jabatan',
                    'required' => false,
                    'prefill' => 'reporter.job_level',
                    'options' => [
                        ['value' => 'Staff', 'label' => 'Staff'],
                        ['value' => 'Manager', 'label' => 'Manager'],
                    ],
                ],
                ['key' => 'nik', 'type' => 'text', 'label' => 'NIK', 'required' => false, 'prefill' => 'reporter.nik'],
            ],
        ];
    }

    /**
     * Form dengan visible_if yang mengacu ke field otomatis dan aturan yang
     * bisa dilanggar nilai identitas helpdesk.
     *
     * @return array<string, mixed>
     */
    private function conditionalFormDefinition(): array
    {
        return [
            'key' => 'IK',
            'title' => 'Instruksi Kerja (IK)',
            'fields' => [
                [
                    'key' => 'jabatan',
                    'type' => 'select',
                    'label' => 'Jabatan',
                    'required' => false,
                    'prefill' => 'reporter.job_level',
                    'options' => [['value' => 'Staff', 'label' => 'Staff'], ['value' => 'Manager', 'label' => 'Manager']],
                ],
                ['key' => 'approval_direksi', 'type' => 'text', 'label' => 'Approval direksi', 'required' => true, 'visible_if' => ['field' => 'jabatan', 'equals' => 'Manager']],
                ['key' => 'catatan_staff', 'type' => 'text', 'label' => 'Catatan staff', 'required' => true, 'visible_if' => ['field' => 'jabatan', 'equals' => 'Staff']],
                [
                    'key' => 'badan_usaha',
                    'type' => 'select',
                    'label' => 'Badan usaha',
                    'required' => true,
                    'prefill' => 'ticket.business_entity',
                    'options' => [['value' => 'CS', 'label' => 'Complete Selular'], ['value' => 'LAIN', 'label' => 'Lainnya']],
                ],
                ['key' => 'nomor_cs', 'type' => 'text', 'label' => 'Nomor CS', 'required' => true, 'visible_if' => ['field' => 'badan_usaha', 'equals' => 'CS']],
                [
                    'key' => 'perlu_approval',
                    'type' => 'radio',
                    'label' => 'Perlu approval',
                    'required' => true,
                    'options' => [['value' => 'ya', 'label' => 'Ya'], ['value' => 'tidak', 'label' => 'Tidak']],
                ],
                ['key' => 'email_atasan', 'type' => 'email', 'label' => 'Email atasan', 'required' => false, 'prefill' => 'reporter.email', 'visible_if' => ['field' => 'perlu_approval', 'equals' => 'ya']],
                ['key' => 'divisi', 'type' => 'text', 'label' => 'Divisi', 'required' => true, 'prefill' => 'reporter.unit', 'rules' => ['max' => 5]],
                ['key' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => false, 'prefill' => 'reporter.email'],
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

    private function talentaReporter(): User
    {
        $unit = Unit::query()->create(['name' => 'Marketing Area']);

        return User::query()->create([
            'name' => 'Rina Pelapor',
            'email' => 'rina@complete.test',
            'password' => null,
            'phone' => '6281234500888',
            'identity' => '3201000000001',
            'unit_id' => $unit->id,
            'job_title' => 'Marketing Executive',
            'job_level' => 'manager',
            'business_entity_id' => $this->entity->id,
            'is_active' => true,
        ]);
    }

    /**
     * Isian non-file setiap POST /tickets yang dikirim ke sistem tujuan.
     *
     * @return list<Collection<string, mixed>>
     */
    private function sentTicketFields(): array
    {
        return Http::recorded(fn (HttpRequest $request): bool => $request->url() === self::BASE.'/tickets')
            ->map(fn (array $pair): Collection => collect($pair[0]->data())
                ->filter(fn (array $part): bool => ! isset($part['filename']))
                ->mapWithKeys(fn (array $part): array => [$part['name'] => $part['contents']]))
            ->values()
            ->all();
    }

    private function legacyExternalTicket(User $owner, string $statusCode, string $statusLabel): Ticket
    {
        $ticket = Ticket::query()->create([
            'priority_id' => $this->priority->id,
            'unit_id' => $this->busdev->id,
            'owner_id' => $owner->id,
            'problem_category_id' => $this->category->id,
            'integration_id' => $this->integration->id,
            'title' => 'IK Penerimaan Barang',
            'description' => '<p>Diteruskan ke Modul OD · Ref IK-20261125-AB3D</p>',
            'ticket_statuses_id' => TicketStatus::OPEN,
        ]);
        app(ExternalTicketService::class)->applySnapshot($ticket, $this->snapshot($statusCode, $statusLabel));

        return $ticket->refresh();
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
