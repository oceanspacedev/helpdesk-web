<?php

namespace Tests\Feature;

use App\Enums\TicketWorkflowAction;
use App\Models\BusinessEntity;
use App\Models\Integration;
use App\Models\IntegrationEvent;
use App\Models\Priority;
use App\Models\ProblemCategory;
use App\Models\Ticket;
use App\Models\TicketHistory;
use App\Models\TicketStatus;
use App\Models\Unit;
use App\Models\UnitSla;
use App\Models\User;
use App\Notifications\ExternalTicketCommentNotification;
use App\Notifications\NewTicketNotification;
use App\Notifications\TicketStatusChangedNotification;
use App\Notifications\TicketSubmittedNotification;
use App\Policies\TicketPolicy;
use App\Services\Integrations\External\ExternalFormSchema;
use App\Services\Integrations\External\ExternalNotFoundException;
use App\Services\Integrations\External\ExternalSystemClient;
use App\Services\Integrations\External\ExternalTicketService;
use App\Services\Integrations\External\ExternalUnavailableException;
use App\Services\Integrations\External\ExternalValidationException;
use App\Services\Integrations\HelpdeskClassificationResolver;
use App\Services\Integrations\HelpdeskFormOptionsService;
use App\Services\Integrations\HelpdeskOperationalClassifier;
use App\Services\Integrations\HelpdeskTicketCreationService;
use App\Services\TicketWorkflowService;
use App\Support\HelpdeskTicketNumber;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesHelpdeskIntegrationSchema;
use Tests\TestCase;

class ExternalIntegrationTest extends TestCase
{
    use CreatesHelpdeskIntegrationSchema;

    private const BASE = 'https://od.test/api/helpdesk/v1';

    private Integration $integration;

    private Unit $unit;

    private ProblemCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTestSchema();
        Cache::flush();
        Notification::fake();
        Http::preventStrayRequests();

        Priority::query()->create(['name' => 'Medium']);

        $this->integration = Integration::query()->create([
            'name' => 'Modul OD',
            'base_url' => self::BASE.'/',
            'token' => 'rahasia-token-od',
            'webhook_secret' => 'rahasia-webhook-od',
            'is_active' => true,
            'timeout_seconds' => 10,
        ]);
        $this->unit = Unit::query()->create(['name' => 'BUSDEV', 'integration_id' => $this->integration->id]);
        $this->category = ProblemCategory::query()->create([
            'unit_id' => $this->unit->id,
            'name' => 'Instruksi Kerja (IK)',
            'external_key' => 'IK',
        ]);
    }

    public function test_client_sends_bearer_token_and_parses_categories(): void
    {
        Http::fake([
            self::BASE.'/categories' => Http::response(['data' => [
                ['key' => 'IK', 'name' => 'Instruksi Kerja', 'active' => true],
                ['key' => 'SOP', 'name' => 'SOP', 'active' => false],
            ]]),
        ]);

        $categories = ExternalSystemClient::for($this->integration)->categories();

        $this->assertSame([
            ['key' => 'IK', 'name' => 'Instruksi Kerja', 'active' => true],
            ['key' => 'SOP', 'name' => 'SOP', 'active' => false],
        ], $categories);
        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === self::BASE.'/categories'
            && $request->hasHeader('Authorization', 'Bearer rahasia-token-od')
            && $request->hasHeader('Accept', 'application/json'));
    }

    public function test_client_maps_error_responses_to_typed_exceptions(): void
    {
        Http::fake([
            self::BASE.'/forms/IK' => Http::response(['message' => 'Not found'], 404),
            self::BASE.'/forms/SOP' => Http::response(['message' => 'boom'], 500),
            self::BASE.'/forms/SE' => Http::response(['message' => 'Unauthenticated.'], 401),
            self::BASE.'/forms/MI' => fn () => throw new ConnectionException('timeout'),
            self::BASE.'/forms/BAD' => Http::response(['nope' => true]),
            self::BASE.'/tickets' => Http::response([
                'message' => 'The given data was invalid.',
                'errors' => ['answers.proses.0.judul' => ['Judul proses wajib diisi.']],
            ], 422),
        ]);
        $client = ExternalSystemClient::for($this->integration);

        $this->assertThrows(fn () => $client->form('IK'), ExternalNotFoundException::class);
        $this->assertThrows(fn () => $client->form('SOP'), ExternalUnavailableException::class);
        $this->assertThrows(fn () => $client->form('SE'), ExternalUnavailableException::class);
        $this->assertThrows(fn () => $client->form('MI'), ExternalUnavailableException::class);
        $this->assertThrows(fn () => $client->form('BAD'), ExternalUnavailableException::class);

        try {
            $client->submit(['title' => 'x']);
            $this->fail('422 harus menjadi ExternalValidationException.');
        } catch (ExternalValidationException $exception) {
            $this->assertSame(['answers.proses.0.judul' => ['Judul proses wajib diisi.']], $exception->errors());
            $this->assertSame(
                ['data.answers.proses.0.judul' => ['Judul proses wajib diisi.']],
                $exception->toValidationException('data.')->errors(),
            );
        }

        $this->integration->update(['is_active' => false]);
        $this->assertThrows(
            fn () => ExternalSystemClient::for($this->integration->fresh())->categories(),
            ExternalUnavailableException::class,
        );
    }

    public function test_submit_creates_thin_ticket_without_internal_routing(): void
    {
        $pic = User::query()->create([
            'name' => 'PIC OD',
            'email' => 'pic@example.test',
            'phone' => '6281200000001',
            'unit_id' => $this->unit->id,
            'is_active' => true,
        ]);
        $this->category->defaultResponsibles()->attach($pic->id);
        $reporterUnit = Unit::query()->create(['name' => 'Finance']);
        $entity = BusinessEntity::query()->create(['name' => 'Complete Selular']);
        $reporter = $this->helpdeskUser();
        $reporter->forceFill([
            'identity' => '3201001',
            'unit_id' => $reporterUnit->id,
            'job_title' => 'Staff Finance',
            'business_entity_id' => $entity->id,
        ])->save();

        Http::fake([self::BASE.'/tickets' => Http::response(['data' => $this->snapshot('in_progress', 'Drafting')], 201)]);

        $ticket = app(ExternalTicketService::class)->submit(
            $this->category,
            $reporter,
            'IK Penerimaan Barang',
            ['tujuan' => 'Standarisasi', 'proses' => [['judul' => 'Terima barang', 'lampiran' => null]], 'setuju' => true],
            ['dokumen' => [UploadedFile::fake()->create('draft.pdf', 10, 'application/pdf')]],
            'idem-001',
        );

        $this->assertTrue($ticket->isExternal());
        $this->assertSame($this->integration->id, $ticket->integration_id);
        $this->assertSame('IK-20261125-AB3D', $ticket->external_ref);
        $this->assertSame('in_progress', $ticket->external_status_code);
        $this->assertSame('Drafting', $ticket->external_status_label);
        $this->assertSame(TicketStatus::IN_PROGRESS, $ticket->ticket_statuses_id);
        $this->assertSame($this->unit->id, $ticket->unit_id);
        $this->assertSame($this->category->id, $ticket->problem_category_id);
        $this->assertSame($reporter->id, $ticket->owner_id);
        $this->assertSame($reporterUnit->id, $ticket->reporter_unit_id);
        $this->assertSame('Staff Finance', $ticket->reporter_job_title);
        $this->assertSame($entity->id, $ticket->business_entities_id);
        $this->assertSame('idem-001', $ticket->external_idempotency_key);
        $this->assertNull($ticket->responsible_id, 'Tiket eksternal tidak boleh ditugaskan ke PIC helpdesk.');
        $this->assertStringContainsString('Ref IK-20261125-AB3D', $ticket->description);
        // Ringkasan snapshot yang bisa berubah tidak dibekukan ke deskripsi.
        $this->assertStringNotContainsString('<ul>', $ticket->description);
        $this->assertSame('Drafting', data_get($ticket->external_snapshot, 'status.label'));
        $this->assertNotNull($ticket->external_synced_at);

        Http::assertSent(function (HttpRequest $request) use ($ticket): bool {
            $fields = collect($request->data())->mapWithKeys(fn (array $part) => [$part['name'] => $part['contents']]);

            return $request->method() === 'POST'
                && $request->isMultipart()
                && $fields['idempotency_key'] === 'idem-001'
                && $fields['helpdesk_ticket_number'] === HelpdeskTicketNumber::format($ticket)
                && $fields['category_key'] === 'IK'
                && $fields['reporter[nik]'] === '3201001'
                && $fields['reporter[name]'] === 'Apri'
                && $fields['reporter[unit]'] === 'Finance'
                && $fields['reporter[business_entity]'] === 'Complete Selular'
                && $fields['answers[proses][0][judul]'] === 'Terima barang'
                && $fields['answers[setuju]'] === '1'
                && ! $fields->has('answers[proses][0][lampiran]')
                && $request->hasFile('answers[dokumen][]', null, 'draft.pdf');
        });

        Notification::assertNotSentTo($pic, NewTicketNotification::class);
        Notification::assertSentToTimes($reporter, TicketSubmittedNotification::class, 1);
        // Snapshot pertama "in_progress" bukan perubahan status: tidak ada
        // notifikasi "sedang diproses" dan hanya satu riwayat (aktor pelapor).
        Notification::assertNotSentTo($reporter, TicketStatusChangedNotification::class);
        $history = TicketHistory::query()->where('ticket_id', $ticket->id)->get();
        $this->assertCount(1, $history);
        $this->assertSame(TicketStatus::IN_PROGRESS, (int) $history->first()->ticket_statuses_id);
        $this->assertSame($reporter->id, (int) $history->first()->user_id);
    }

    public function test_retry_after_timeout_reuses_the_reserved_ticket_number(): void
    {
        $numbers = [];
        $calls = 0;
        Http::fake([self::BASE.'/tickets' => function (HttpRequest $request) use (&$numbers, &$calls) {
            $fields = collect($request->data())->mapWithKeys(fn (array $part) => [$part['name'] => $part['contents']]);
            $numbers[] = $fields['helpdesk_ticket_number'];
            $calls++;

            // Percobaan pertama: sistem eksternal menyimpan tiket tetapi
            // helpdesk kehabisan waktu menunggu respons.
            if ($calls === 1) {
                throw new ConnectionException('timeout');
            }

            return Http::response(['data' => $this->snapshot()], 200);
        }]);
        $service = app(ExternalTicketService::class);
        $reporter = $this->helpdeskUser();

        $this->assertThrows(
            fn () => $service->submit($this->category, $reporter, 'IK', ['a' => 'b'], [], 'idem-timeout'),
            ExternalUnavailableException::class,
        );
        $this->assertSame(0, Ticket::query()->count(), 'Baris cadangan tidak boleh tampil sebelum diterima.');
        $this->assertSame(0, Ticket::query()->withTrashed()->withoutPendingExternal()->count());
        $this->assertSame(0, TicketHistory::query()->count());
        Notification::assertNothingSent();

        $ticket = $service->submit($this->category, $reporter, 'IK', ['a' => 'b'], [], 'idem-timeout');

        $this->assertCount(2, $numbers);
        $this->assertSame($numbers[0], $numbers[1], 'Nomor helpdesk harus sama saat dikirim ulang.');
        $this->assertSame(HelpdeskTicketNumber::format($ticket), $numbers[0]);
        $this->assertSame(1, Ticket::query()->count());
        $this->assertSame(1, Ticket::query()->withTrashed()->count());
        $this->assertSame('IK-20261125-AB3D', $ticket->external_ref);
        $this->assertSame(1, TicketHistory::query()->where('ticket_id', $ticket->id)->count());
        Notification::assertSentToTimes($reporter, TicketSubmittedNotification::class, 1);
    }

    public function test_submit_is_replay_safe_for_the_same_idempotency_key(): void
    {
        Http::fake([self::BASE.'/tickets' => Http::response(['data' => $this->snapshot()], 201)]);
        $service = app(ExternalTicketService::class);
        $reporter = $this->helpdeskUser();

        $first = $service->submit($this->category, $reporter, 'IK', ['a' => 'b'], [], 'idem-replay');
        $second = $service->submit($this->category, $reporter, 'IK', ['a' => 'b'], [], 'idem-replay');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Ticket::query()->count());
        Http::assertSentCount(1);
    }

    public function test_submit_rolls_back_on_validation_error_and_unavailability(): void
    {
        Http::fakeSequence(self::BASE.'/tickets')
            ->push(['message' => 'invalid', 'errors' => ['answers.tujuan' => ['Tujuan wajib diisi.']]], 422)
            ->push(['message' => 'down'], 503);
        $service = app(ExternalTicketService::class);
        $reporter = $this->helpdeskUser();

        try {
            $service->submit($this->category, $reporter, 'IK', [], [], 'idem-422');
            $this->fail('422 harus dilempar.');
        } catch (ExternalValidationException $exception) {
            $this->assertSame(['answers.tujuan' => ['Tujuan wajib diisi.']], $exception->errors());
        }

        $this->assertThrows(
            fn () => $service->submit($this->category, $reporter, 'IK', [], [], 'idem-503'),
            ExternalUnavailableException::class,
        );

        // 422 menghapus cadangan; 503 menyimpannya tersembunyi (soft delete)
        // agar percobaan ulang memakai nomor yang sama.
        $this->assertSame(0, Ticket::query()->count());
        $this->assertSame(0, Ticket::query()->withTrashed()->where('external_idempotency_key', 'idem-422')->count());
        $this->assertSame(1, Ticket::query()->onlyTrashed()->where('external_idempotency_key', 'idem-503')->whereNull('external_ref')->count());
        $this->assertSame(0, TicketHistory::query()->count());
        Notification::assertNothingSent();
    }

    public function test_refresh_fetches_snapshot_and_maps_status(): void
    {
        $ticket = $this->externalTicket();
        Http::fake([self::BASE.'/tickets/IK-20261125-AB3D' => Http::response(['data' => $this->snapshot('done', 'Selesai', '2026-11-25T12:00:00+07:00')])]);

        $this->assertTrue(app(ExternalTicketService::class)->refresh($ticket));

        $ticket->refresh();
        $this->assertSame(TicketStatus::CLOSED, $ticket->ticket_statuses_id);
        $this->assertSame('Selesai', $ticket->external_status_label);
        $this->assertNotNull($ticket->solved_at);
        $this->assertNull($ticket->sla_due_at ?? null);
    }

    public function test_webhook_rejects_bad_signature_and_stale_timestamp(): void
    {
        $ticket = $this->externalTicket();
        $body = $this->eventBody('evt-1', $this->snapshot('done', 'Selesai', '2026-11-25T12:00:00+07:00'));

        $this->postEvent($body, signature: 'sha256='.str_repeat('0', 64))->assertStatus(401);
        $this->postEvent($body, timestamp: time() - 301)->assertStatus(401);
        $this->postEvent($body, secret: 'salah')->assertStatus(401);

        $this->assertSame(TicketStatus::OPEN, $ticket->fresh()->ticket_statuses_id);
        $this->assertSame(0, IntegrationEvent::query()->count());
    }

    public function test_webhook_applies_status_change_with_history_and_notifications(): void
    {
        $ticket = $this->externalTicket();
        $owner = User::query()->find($ticket->owner_id);
        $snapshot = $this->snapshot('in_progress', 'Proses Esign', '2026-11-25T12:00:00+07:00');
        $snapshot['comments'] = [['id' => '12', 'author' => 'Tim OD', 'body' => 'Draft sedang ditinjau.', 'at' => '2026-11-25T11:00:00+07:00']];

        $this->postEvent($this->eventBody('evt-2', $snapshot))
            ->assertOk()
            ->assertJson(['status' => 'processed']);

        $ticket->refresh();
        $this->assertSame(TicketStatus::IN_PROGRESS, $ticket->ticket_statuses_id);
        $this->assertSame('Proses Esign', $ticket->external_status_label);
        $this->assertDatabaseHas('ticket_histories', [
            'ticket_id' => $ticket->id,
            'ticket_statuses_id' => TicketStatus::IN_PROGRESS,
            'user_id' => null,
        ]);
        Notification::assertSentTo($owner, TicketStatusChangedNotification::class);
        Notification::assertSentTo($owner, ExternalTicketCommentNotification::class, function (ExternalTicketCommentNotification $notification) use ($owner): bool {
            return str_contains((string) $notification->toArray($owner)['comment'], 'Draft sedang ditinjau.');
        });

        // Event sama dikirim ulang: 200 tanpa efek.
        $historyCount = TicketHistory::query()->count();
        $this->postEvent($this->eventBody('evt-2', $snapshot))
            ->assertOk()
            ->assertJson(['status' => 'duplicate']);
        $this->assertSame($historyCount, TicketHistory::query()->count());
        Notification::assertSentToTimes($owner, ExternalTicketCommentNotification::class, 1);

        // Snapshot lebih lama diabaikan.
        $this->postEvent($this->eventBody('evt-3', $this->snapshot('cancelled', 'Batal', '2026-11-25T09:00:00+07:00')))
            ->assertOk()
            ->assertJson(['status' => 'stale']);
        $this->assertSame(TicketStatus::IN_PROGRESS, $ticket->fresh()->ticket_statuses_id);

        // Snapshot baru tanpa perubahan status/komentar: tidak ada riwayat atau notifikasi tambahan.
        $same = $snapshot;
        $same['updated_at'] = '2026-11-25T13:00:00+07:00';
        $this->postEvent($this->eventBody('evt-4', $same))->assertOk()->assertJson(['status' => 'processed']);
        $this->assertSame($historyCount, TicketHistory::query()->count());
        Notification::assertSentToTimes($owner, TicketStatusChangedNotification::class, 1);
        Notification::assertSentToTimes($owner, ExternalTicketCommentNotification::class, 1);
    }

    public function test_webhook_for_unknown_ref_is_retryable(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['ref'] = 'BELUM-ADA';

        $this->postEvent($this->eventBody('evt-unknown', $snapshot))->assertStatus(404);
        $this->assertSame(0, IntegrationEvent::query()->count(), 'Event tanpa tiket tidak dicatat agar retry tetap diproses.');

        $ticket = $this->externalTicket();
        $ticket->delete();
        $this->postEvent($this->eventBody('evt-trashed', $this->snapshot('done', 'Selesai', '2026-11-25T12:00:00+07:00')))
            ->assertOk()
            ->assertJson(['status' => 'ignored']);
        $this->assertSame(TicketStatus::OPEN, Ticket::withTrashed()->find($ticket->id)->ticket_statuses_id);

        $this->integration->update(['is_active' => false]);
        $this->postEvent($this->eventBody('evt-inactive', $this->snapshot()))->assertStatus(403);
    }

    public function test_workflow_and_policies_refuse_external_tickets(): void
    {
        $ticket = $this->externalTicket();
        $staff = User::query()->create([
            'name' => 'Staff BUSDEV',
            'email' => 'staff@example.test',
            'phone' => '6281200000002',
            'unit_id' => $this->unit->id,
            'is_active' => true,
        ]);

        $result = app(TicketWorkflowService::class)->transition(
            HelpdeskTicketNumber::format($ticket),
            $staff,
            TicketWorkflowAction::PROCESS,
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('external_ticket', $result['code']);
        $this->assertSame(TicketStatus::OPEN, $ticket->fresh()->ticket_statuses_id);
        $this->assertFalse((new TicketPolicy)->process($staff, $ticket));
        $this->assertFalse((new TicketPolicy)->update($staff, $ticket));
    }

    public function test_mcp_intake_refuses_external_units_and_classifier_skips_them(): void
    {
        $owner = $this->helpdeskUser();
        $entity = BusinessEntity::query()->create(['name' => 'Complete Selular']);

        $result = app(HelpdeskTicketCreationService::class)->create([
            'idempotency_key' => 'mcp-external-1',
            'actor' => ['phone' => $owner->phone, 'is_verified' => true, 'channel' => 'mcp'],
            'ticket_data' => [
                'title' => 'Minta IK baru',
                'description' => 'Butuh instruksi kerja penerimaan barang.',
                'unit_id' => $this->unit->id,
                'problem_category_id' => $this->category->id,
                'priority_id' => Priority::query()->value('id'),
                'business_entities_id' => $entity->id,
                'consent_to_create' => true,
            ],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('HELPDESK_EXTERNAL_UNIT', data_get($result, 'error.code'));
        $this->assertStringContainsString('form web', (string) $result['message']);
        $this->assertSame(route('public-tickets.create'), data_get($result, 'data.form_url'));
        $this->assertSame(0, Ticket::query()->count());

        $it = Unit::query()->create(['name' => 'IT']);
        $classifier = app(HelpdeskOperationalClassifier::class);

        // Permintaan dokumen BUSDEV tetap dikenali sebagai BUSDEV (bukan
        // dialihkan diam-diam ke antrean IT) agar pelapor diarahkan ke form web.
        $patch = $classifier->classify('Tolong buatkan instruksi kerja penerimaan barang', [], $owner);
        $this->assertSame((string) $this->unit->id, (string) ($patch['unit_id'] ?? ''));
        $this->assertTrue($patch['unit_external']);
        $this->assertFalse($patch['unit_assumed']);
        $this->assertArrayNotHasKey('problem_category_id', $patch);
        $this->assertSame(0, ProblemCategory::query()->where('unit_id', $this->unit->id)->where('name', HelpdeskOperationalClassifier::UNCLASSIFIED_CATEGORY)->count());

        // Laporan tanpa aturan tetap jatuh ke unit internal.
        $fallback = $classifier->classify('Ada kendala aneh di ruang rapat', [], $owner);
        $this->assertSame((string) $it->id, (string) ($fallback['unit_id'] ?? ''));
        $this->assertFalse($fallback['unit_external']);
    }

    public function test_chat_resolver_hides_external_units_and_rejects_them_when_named(): void
    {
        $it = Unit::query()->create(['name' => 'IT']);
        ProblemCategory::query()->create(['unit_id' => $it->id, 'name' => 'Jaringan']);
        $resolver = app(HelpdeskClassificationResolver::class);
        $owner = $this->helpdeskUser();

        $options = app(HelpdeskFormOptionsService::class)->formOptions(internalOnly: true);
        $this->assertSame(['IT'], array_column($options['units'], 'name'));
        $this->assertNotContains($this->category->id, array_column($options['problem_categories'], 'id'));
        // Form web tetap menampilkan unit berintegrasi.
        $this->assertContains('BUSDEV', array_column(app(HelpdeskFormOptionsService::class)->formOptions()['units'], 'name'));

        $prompt = $resolver->buildFieldValidationMessage(['field' => 'unit_id', 'reason' => 'missing', 'provided_value' => null], $options);
        $this->assertStringNotContainsString('BUSDEV', $prompt);

        // Nomor 1 di daftar chat adalah IT, bukan BUSDEV.
        $first = $resolver->validateField('unit_id', '1', []);
        $this->assertTrue($first['ok']);
        $this->assertSame($it->id, $first['resolved']['id']);

        $named = $resolver->validateField('unit_id', 'BUSDEV', []);
        $this->assertFalse($named['ok']);
        $this->assertSame('external_unit', $named['reason']);
        $this->assertStringContainsString('form web', $named['message']);
        $this->assertSame(route('public-tickets.create'), $named['form_url']);

        // Divisi asal pelapor yang berintegrasi tidak dipakai sebagai tebakan unit.
        Schema::create('user_entities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->morphs('entity');
        });
        $owner->units()->attach($this->unit->id);
        $this->assertNull($resolver->resolveUnit([], $owner->fresh()));
    }

    public function test_prepare_answers_keeps_files_on_their_own_repeater_rows(): void
    {
        $form = ['fields' => [[
            'key' => 'proses',
            'type' => 'repeater',
            'label' => 'Proses',
            'required' => true,
            'fields' => [
                ['key' => 'judul', 'type' => 'text', 'label' => 'Judul', 'required' => true],
                ['key' => 'lampiran', 'type' => 'file', 'label' => 'Lampiran', 'required' => false],
            ],
        ]]];
        $reporter = $this->helpdeskUser();
        $fileB = UploadedFile::fake()->create('b.pdf', 5, 'application/pdf');
        $fileC = UploadedFile::fake()->create('c.pdf', 5, 'application/pdf');

        // Form publik: pohon file jarang, hanya baris 1 dan 2 yang punya lampiran.
        [$answers, $files] = app(ExternalFormSchema::class)->prepareAnswers(
            $form,
            ['proses' => [['judul' => 'A'], ['judul' => 'B'], ['judul' => 'C']]],
            ['proses' => [2 => ['lampiran' => $fileC], 1 => ['lampiran' => $fileB]]],
            $reporter,
            autoPrefill: false,
        );

        $this->assertSame([['judul' => 'A'], ['judul' => 'B'], ['judul' => 'C']], $answers['proses']);
        $this->assertSame([1, 2], array_keys($files['proses']));
        $this->assertSame($fileB, $files['proses'][1]['lampiran']);
        $this->assertSame($fileC, $files['proses'][2]['lampiran']);

        // Panel: kunci baris UUID repeater Filament, hanya baris kedua berisi file.
        [$answers, $files] = app(ExternalFormSchema::class)->prepareAnswers(
            $form,
            ['proses' => ['uuid-a' => ['judul' => 'A'], 'uuid-b' => ['judul' => 'B']]],
            ['proses' => ['uuid-b' => ['lampiran' => $fileB]]],
            $reporter,
            autoPrefill: false,
        );

        $this->assertSame([['judul' => 'A'], ['judul' => 'B']], $answers['proses']);
        $this->assertSame([1], array_keys($files['proses']));
        $this->assertSame($fileB, $files['proses'][1]['lampiran']);
    }

    public function test_external_tickets_never_get_helpdesk_sla(): void
    {
        Schema::create('unit_slas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('unit_id');
            $table->foreignId('priority_id');
            $table->integer('target_hours');
            $table->timestamps();
        });
        Schema::table('tickets', function (Blueprint $table): void {
            $table->timestamp('sla_due_at')->nullable();
            $table->boolean('is_sla_met')->nullable();
            $table->timestamp('sla_warning_sent_at')->nullable();
        });
        UnitSla::query()->create([
            'unit_id' => $this->unit->id,
            'priority_id' => Priority::query()->value('id'),
            'target_hours' => 1,
        ]);

        $reporter = $this->helpdeskUser();
        Http::fake([self::BASE.'/tickets' => Http::response(['data' => $this->snapshot('in_progress', 'Drafting')], 201)]);
        $ticket = app(ExternalTicketService::class)->submit($this->category, $reporter, 'IK', ['a' => 'b'], [], 'idem-sla');

        $this->assertSame(TicketStatus::IN_PROGRESS, $ticket->ticket_statuses_id);
        $this->assertNotNull($ticket->approved_at);
        $this->assertNull($ticket->sla_due_at);
        $this->assertNull($ticket->is_sla_met);

        app(ExternalTicketService::class)->applySnapshot($ticket, $this->snapshot('done', 'Selesai', '2026-11-25T12:00:00+07:00'));
        $ticket->refresh();
        $this->assertSame(TicketStatus::CLOSED, $ticket->ticket_statuses_id);
        $this->assertNull($ticket->sla_due_at);
        $this->assertNull($ticket->is_sla_met);

        // Pembanding: tiket internal di unit dengan aturan SLA yang sama mendapat tenggat.
        $internal = Ticket::query()->create([
            'priority_id' => Priority::query()->value('id'),
            'unit_id' => $this->unit->id,
            'owner_id' => $reporter->id,
            'problem_category_id' => $this->category->id,
            'title' => 'Internal',
            'description' => 'x',
            'ticket_statuses_id' => TicketStatus::OPEN,
        ]);
        $internal->update(['ticket_statuses_id' => TicketStatus::IN_PROGRESS]);
        $this->assertNotNull($internal->refresh()->sla_due_at);

        // Perintah peringatan SLA mengabaikan tiket eksternal walau ada tenggat lama.
        Ticket::query()->whereKey($ticket->id)->update([
            'ticket_statuses_id' => TicketStatus::IN_PROGRESS,
            'sla_due_at' => now()->addHour(),
        ]);
        Ticket::query()->whereKey($internal->id)->update(['sla_warning_sent_at' => now()]);
        $this->artisan('sla:check-warnings')->assertSuccessful();
        $this->assertNull($ticket->refresh()->sla_warning_sent_at);
    }

    private function externalTicket(): Ticket
    {
        Http::fake([self::BASE.'/tickets' => Http::response(['data' => $this->snapshot()], 201)]);

        $ticket = app(ExternalTicketService::class)->submit(
            $this->category,
            $this->helpdeskUser(),
            'IK Penerimaan Barang',
            ['tujuan' => 'Standarisasi'],
            [],
            'idem-fixture',
        );

        Notification::fake();

        return $ticket;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(string $code = 'open', string $label = 'Diterima', string $updatedAt = '2026-11-25T10:00:00+07:00'): array
    {
        return [
            'ref' => 'IK-20261125-AB3D',
            'url' => 'https://od.test/ik/1',
            'status' => ['code' => $code, 'label' => $label],
            'summary' => [['label' => 'Kategori', 'value' => 'Instruksi Kerja']],
            'items' => [['title' => 'IK Penerimaan Barang', 'status' => ['code' => $code, 'label' => $label], 'pic' => 'Nama PIC']],
            'timeline' => [['at' => '2026-11-25T10:00:00+07:00', 'label' => 'Pengajuan diterima', 'note' => null]],
            'comments' => [],
            'files' => [],
            'updated_at' => $updatedAt,
        ];
    }

    private function eventBody(string $eventId, array $snapshot): string
    {
        return (string) json_encode([
            'event_id' => $eventId,
            'type' => 'ticket.updated',
            'occurred_at' => $snapshot['updated_at'],
            'data' => $snapshot,
        ]);
    }

    private function postEvent(string $body, ?int $timestamp = null, ?string $signature = null, string $secret = 'rahasia-webhook-od'): TestResponse
    {
        $timestamp ??= time();
        $signature ??= 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        return $this->call(
            'POST',
            '/api/integrations/'.$this->integration->id.'/events',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_HELPDESK_TIMESTAMP' => (string) $timestamp,
                'HTTP_X_HELPDESK_SIGNATURE' => $signature,
            ],
            content: $body,
        );
    }
}
