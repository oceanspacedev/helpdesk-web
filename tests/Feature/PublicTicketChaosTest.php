<?php

namespace Tests\Feature;

use App\Models\BusinessEntity;
use App\Models\McpTicketCreationRequest;
use App\Models\Priority;
use App\Models\ProblemCategory;
use App\Models\Ticket;
use App\Models\TicketHistory;
use App\Models\TicketStatus;
use App\Models\Unit;
use App\Models\User;
use App\Support\HelpdeskIntegrationClient;
use App\Services\Integrations\HelpdeskReporterIdentityService;
use App\Services\Integrations\PublicReporterIdentityService;
use App\Services\Integrations\PublicTicketSubmissionService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\CreatesHelpdeskIntegrationSchema;
use Tests\TestCase;

class PublicTicketChaosTest extends TestCase
{
    use CreatesHelpdeskIntegrationSchema;

    private const DEVICE_SESSION_KEY = 'helpdesk.public_reporter.device_token';

    private const SUBMISSION_SESSION_KEY = 'helpdesk.public_ticket.submission_token';

    private Unit $unit;

    private ProblemCategory $category;

    private Priority $priority;

    private BusinessEntity $businessEntity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTestSchema();
        Cache::flush();
        NotificationFacade::fake();
        Storage::fake('public');
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.41']);

        $this->unit = Unit::query()->create(['name' => 'IT']);
        $this->category = ProblemCategory::query()->create([
            'unit_id' => $this->unit->id,
            'name' => 'Akses Akun',
        ]);
        $this->priority = Priority::query()->create([
            'id' => Priority::MEDIUM,
            'name' => 'Medium',
        ]);
        $this->businessEntity = BusinessEntity::query()->create([
            'name' => 'Complete Selular',
        ]);
    }

    public function test_public_http_submit_succeeds_when_ticket_history_insert_fails(): void
    {
        TicketHistory::creating(function (): void {
            throw new RuntimeException('history down');
        });

        $reporter = $this->reporter('Reporter Chaos History', '6281234500401');
        $submissionToken = str_repeat('H', 40);
        $this->rememberReporter($reporter, str_repeat('h', 64), $submissionToken);

        $this->post(route('public-tickets.store'), $this->validTicketPayload($submissionToken, [
            'title' => 'Wifi kantor putus-putus',
            'description' => 'Koneksi terputus setiap lima menit sejak pagi.',
        ]))
            ->assertRedirect(route('public-tickets.create'))
            ->assertSessionHas('ticket_success')
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Ticket::query()->count());
        $this->assertSame($reporter->id, Ticket::query()->sole()->owner_id);
        $this->assertSame(TicketStatus::OPEN, (int) Ticket::query()->sole()->ticket_statuses_id);
        $this->assertSame(0, TicketHistory::query()->count());
    }

    public function test_public_http_submit_succeeds_when_post_commit_hydration_throws(): void
    {
        $reporter = $this->reporter('Reporter Chaos Hydration', '6281234500402');
        $submissionToken = str_repeat('Y', 40);
        $this->rememberReporter($reporter, str_repeat('y', 64), $submissionToken);
        $throwOnHydration = false;

        DB::listen(function (QueryExecuted $query) use (&$throwOnHydration): void {
            $sql = strtolower($query->sql);
            if (str_starts_with(ltrim($sql), 'update')
                && str_contains($sql, 'mcp_ticket_creation_requests')
                && in_array('completed', $query->bindings, true)) {
                $throwOnHydration = true;

                return;
            }

            if ($throwOnHydration
                && str_starts_with(ltrim($sql), 'select')
                && str_contains($sql, 'priorities')) {
                throw new RuntimeException('forced post-commit hydration failure');
            }
        });

        $this->post(route('public-tickets.store'), $this->validTicketPayload($submissionToken, [
            'title' => 'Printer gudang error',
            'description' => 'Tidak bisa cetak surat jalan sejak kemarin sore.',
        ]))
            ->assertRedirect(route('public-tickets.create'))
            ->assertSessionHas('ticket_success.number')
            ->assertSessionHas('ticket_success.title', 'Printer gudang error');

        $this->assertTrue($throwOnHydration);
        $this->assertSame(1, Ticket::query()->count());
        $this->assertSame('Printer gudang error', Ticket::query()->sole()->title);
    }

    public function test_unreadable_attachment_is_skipped_and_the_ticket_is_still_collected(): void
    {
        $reporter = $this->reporter('Reporter Chaos File', '6281234500403');
        $service = app(PublicTicketSubmissionService::class);
        $submissionToken = str_repeat('F', 40);
        $good = UploadedFile::fake()->createWithContent('ok.txt', 'isi lampiran yang valid');
        $broken = $this->unreadableUpload('rusak.txt');

        $ticket = $service->create(
            $reporter,
            $this->validTicketPayload($submissionToken, [
                'title' => 'CCTV lantai 2 mati',
                'description' => 'Layar monitor hitam sejak shift malam.',
            ]),
            [$good, $broken],
            $submissionToken,
        );

        $this->assertSame($reporter->id, $ticket->owner_id);
        $this->assertSame(TicketStatus::OPEN, (int) $ticket->ticket_statuses_id);
        $paths = $ticket->supporting_attachments ?? [];
        $this->assertCount(1, $paths);
        Storage::disk('public')->assertExists($paths[0]);
        $this->assertSame(1, Ticket::query()->count());
    }

    public function test_http_submit_recovers_a_committed_ticket_when_create_throws_after_commit(): void
    {
        $reporter = $this->reporter('Reporter Chaos Recover', '6281234500404');
        $submissionToken = str_repeat('R', 40);
        $this->rememberReporter($reporter, str_repeat('r', 64), $submissionToken);

        $committed = Ticket::query()->create([
            'priority_id' => $this->priority->id,
            'unit_id' => $this->unit->id,
            'owner_id' => $reporter->id,
            'problem_category_id' => $this->category->id,
            'title' => 'Laporan yang sudah masuk',
            'description' => '<p>Sudah tersimpan sebelum error sampingan.</p>',
            'ticket_statuses_id' => TicketStatus::OPEN,
            'business_entities_id' => $this->businessEntity->id,
        ]);
        McpTicketCreationRequest::query()->create([
            'client_key' => HelpdeskIntegrationClient::persistentKey('public-helpdesk-form'),
            'key_hash' => hash('sha256', $submissionToken),
            'request_hash' => 'placeholder',
            'status' => 'completed',
            'response' => ['ticket_id' => $committed->id],
        ]);

        $this->mock(PublicTicketSubmissionService::class, function ($mock) use ($committed): void {
            $mock->shouldReceive('create')->once()->andThrow(new RuntimeException('side effect after commit'));
            $mock->shouldReceive('findCommitted')->once()->andReturn($committed);
        });

        $this->post(route('public-tickets.store'), $this->validTicketPayload($submissionToken, [
            'title' => 'Laporan yang sudah masuk',
            'description' => 'Sudah tersimpan sebelum error sampingan.',
        ]))
            ->assertRedirect(route('public-tickets.create'))
            ->assertSessionHas('ticket_success.number')
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Ticket::query()->where('id', $committed->id)->count());
    }

    public function test_completed_idempotency_pointing_at_a_deleted_ticket_still_collects_data(): void
    {
        $reporter = $this->reporter('Reporter Chaos Ghost', '6281234500407');
        $service = app(PublicTicketSubmissionService::class);
        $submissionToken = str_repeat('G', 40);
        $payload = $this->validTicketPayload($submissionToken, [
            'title' => 'VPN cabang tidak nyambung',
            'description' => 'Semua staff cabang tidak bisa masuk VPN.',
        ]);

        $first = $service->create($reporter, $payload, [], $submissionToken);
        $first->forceDelete();

        $second = $service->create($reporter, $payload, [], $submissionToken);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($reporter->id, $second->owner_id);
        $this->assertSame('VPN cabang tidak nyambung', $second->title);
        $this->assertSame(1, Ticket::query()->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function validTicketPayload(string $submissionToken, array $overrides = []): array
    {
        return array_replace([
            'submission_token' => $submissionToken,
            'website' => '',
            'business_entities_id' => $this->businessEntity->id,
            'unit_id' => $this->unit->id,
            'problem_category_id' => $this->category->id,
            'priority_id' => $this->priority->id,
            'title' => 'Laptop tidak dapat terhubung ke jaringan',
            'description' => 'Koneksi terputus sejak pagi dan sudah mencoba memulai ulang perangkat.',
        ], $overrides);
    }

    private function reporter(string $name, string $phone): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => null,
            'password' => null,
            'phone' => $phone,
            'is_active' => true,
        ]);
    }

    private function rememberReporter(User $reporter, string $deviceToken, string $submissionToken): void
    {
        $remembered = app(HelpdeskReporterIdentityService::class)->bind(
            'public-helpdesk-form',
            'web',
            $deviceToken,
            $reporter,
            'phone_input',
        );

        $this->assertTrue($remembered);
        $this->withCookie(PublicReporterIdentityService::COOKIE_NAME, $deviceToken);
        $this->withSession([
            self::DEVICE_SESSION_KEY => $deviceToken,
            self::SUBMISSION_SESSION_KEY => $submissionToken,
        ]);
    }

    private function unreadableUpload(string $name): UploadedFile
    {
        $base = UploadedFile::fake()->create($name, 2);

        return new class($base->getPathname(), $name, $base->getMimeType(), null, true) extends UploadedFile
        {
            public function isValid(): bool
            {
                return true;
            }

            public function getRealPath(): string|false
            {
                return false;
            }

            public function store($path = '', $options = [])
            {
                return false;
            }
        };
    }
}
