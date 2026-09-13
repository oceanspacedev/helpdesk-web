<?php

namespace Tests\Feature;

use App\Models\BusinessEntity;
use App\Models\HelpdeskReporterBinding;
use App\Models\Priority;
use App\Models\ProblemCategory;
use App\Models\Ticket;
use App\Models\TicketHistory;
use App\Models\TicketStatus;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\TicketSubmittedNotification;
use App\Services\Integrations\HelpdeskReporterIdentityService;
use App\Services\Integrations\PublicReporterIdentityService;
use App\Services\Integrations\PublicTicketSubmissionService;
use App\Services\WhatsAppGateway;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\Concerns\CreatesHelpdeskIntegrationSchema;
use Tests\Fakes\RecordingWhatsAppGateway;
use Tests\TestCase;

class PublicTicketFormTest extends TestCase
{
    use CreatesHelpdeskIntegrationSchema;

    private const DEVICE_SESSION_KEY = 'helpdesk.public_reporter.device_token';

    private const SUBMISSION_SESSION_KEY = 'helpdesk.public_ticket.submission_token';

    private RecordingWhatsAppGateway $whatsAppGateway;

    private Unit $unit;

    private Unit $otherUnit;

    private ProblemCategory $category;

    private ProblemCategory $otherCategory;

    private Priority $priority;

    private BusinessEntity $businessEntity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTestSchema();
        Cache::flush();
        Notification::fake();
        Storage::fake('public');

        $this->whatsAppGateway = new RecordingWhatsAppGateway;
        $this->app->instance(WhatsAppGateway::class, $this->whatsAppGateway);
        config()->set('services.whatsapp_gateway.url');
        config()->set('services.whatsapp_gateway.token');
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.31']);

        $this->unit = Unit::query()->create(['name' => 'IT']);
        $this->otherUnit = Unit::query()->create(['name' => 'HR']);
        $this->category = ProblemCategory::query()->create([
            'unit_id' => $this->unit->id,
            'name' => 'Akses Akun',
        ]);
        $this->otherCategory = ProblemCategory::query()->create([
            'unit_id' => $this->otherUnit->id,
            'name' => 'Payroll',
        ]);
        $this->priority = Priority::query()->create([
            'id' => Priority::MEDIUM,
            'name' => 'Medium',
        ]);
        $this->businessEntity = BusinessEntity::query()->create([
            'name' => 'Complete Selular',
        ]);
    }

    public function test_guest_can_open_the_public_ticket_form(): void
    {
        $response = $this->get(route('public-tickets.create'));
        $this->assertIdentifyPage($response)
            ->assertDontSee('Form laporan publik')
            ->assertDontSee('OTP')
            ->assertDontSee('Nama lengkap')
            ->assertDontSee('Pusat bantuan')
            ->assertDontSee('Ceritakan kendalanya')
            ->assertDontSee('Langkah awal')
            ->assertDontSee('Apa yang sedang terjadi?')
            ->assertDontSee('wire:')
            ->assertDontSee('livewire', false);
        $this->assertStringContainsString(
            'no-store',
            (string) $response->headers->get('Cache-Control'),
        );

        $this->assertIdentifyPage($this->get(route('public-tickets.alias')))
            ->assertDontSee('OTP')
            ->assertDontSee('Nama lengkap');

        $this->assertTrue(Route::has('public-reporter.identify'));
        $this->assertFalse(Route::has('public-reporter.send-otp'));
        $this->assertFalse(Route::has('public-reporter.verify-otp'));
        $this->assertFalse(Route::has('public-reporter.register'));
        $this->assertSame([], $this->whatsAppGateway->messages);
        $this->assertTrue(Auth::guest());
        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_existing_reporter_is_reused_by_phone_input_and_returns_directly_to_the_ticket_form(): void
    {
        $reporter = $this->reporter('Reporter Lama', '6281234500101');

        $this->get(route('public-tickets.create'))->assertOk();
        $preIdentificationToken = (string) session()->get(self::DEVICE_SESSION_KEY);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{64}$/D', $preIdentificationToken);
        $this->withCookie(PublicReporterIdentityService::COOKIE_NAME, $preIdentificationToken);

        $identification = $this->post(route('public-reporter.identify'), [
            'phone' => '0812-3450-0101',
        ]);

        $postIdentificationToken = (string) session()->get(self::DEVICE_SESSION_KEY);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{64}$/D', $postIdentificationToken);
        $this->assertNotSame($preIdentificationToken, $postIdentificationToken);
        $identification
            ->assertRedirect(route('public-tickets.create'))
            ->assertSessionDoesntHaveErrors()
            ->assertCookie(PublicReporterIdentityService::COOKIE_NAME, $postIdentificationToken);

        $binding = HelpdeskReporterBinding::query()->sole();
        $this->assertSame($reporter->id, $binding->user_id);
        $this->assertSame('web', $binding->channel);
        $this->assertSame('phone_input', $binding->verification_method);
        $this->assertNull($binding->revoked_at);
        $this->assertSame([], $this->whatsAppGateway->messages);

        $this->assertTicketPage(
            $this->withCookie(PublicReporterIdentityService::COOKIE_NAME, $postIdentificationToken)
                ->get(route('public-tickets.create')),
            '0101',
        )
            ->assertDontSee('Apa yang sedang terjadi?')
            ->assertDontSee('Nama lengkap')
            ->assertDontSee('Reporter Lama')
            ->assertDontSee('Pusat bantuan')
            ->assertDontSee('Ceritakan kendalanya');

        $this->assertMatchesRegularExpression(
            '/^[A-Za-z0-9]{40}$/D',
            (string) session()->get(self::SUBMISSION_SESSION_KEY),
        );
        $this->assertTrue(Auth::guest());
        $this->assertSame(1, User::query()->count());
        $this->assertSame('Reporter Lama', $reporter->fresh()->name);
    }

    public function test_phone_identification_rotates_the_pre_binding_device_token(): void
    {
        $reporter = $this->reporter('Reporter Token Rotasi', '6281234500190');
        $identities = app(HelpdeskReporterIdentityService::class);

        $this->get(route('public-tickets.create'))->assertOk();
        $preIdentificationToken = (string) session()->get(self::DEVICE_SESSION_KEY);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{64}$/D', $preIdentificationToken);
        $this->withCookie(PublicReporterIdentityService::COOKIE_NAME, $preIdentificationToken);

        $identification = $this->post(route('public-reporter.identify'), [
            'phone' => '0812-3450-0190',
        ]);

        $postIdentificationToken = (string) session()->get(self::DEVICE_SESSION_KEY);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{64}$/D', $postIdentificationToken);
        $this->assertNotSame($preIdentificationToken, $postIdentificationToken);
        $identification
            ->assertRedirect(route('public-tickets.create'))
            ->assertCookie(PublicReporterIdentityService::COOKIE_NAME, $postIdentificationToken);

        $this->assertNull($identities->linkedUser(
            'public-helpdesk-form',
            'web',
            $preIdentificationToken,
        ));
        $this->assertSame($reporter->id, $identities->linkedUser(
            'public-helpdesk-form',
            'web',
            $postIdentificationToken,
        )?->id);

        $this->assertDatabaseCount('helpdesk_reporter_bindings', 1);
        $this->assertSame('phone_input', HelpdeskReporterBinding::query()->sole()->verification_method);
        $this->assertSame([], $this->whatsAppGateway->messages);
    }

    public function test_unknown_phone_is_created_automatically_remembered_and_owns_its_ticket_history(): void
    {
        $this->get(route('public-tickets.create'))->assertOk();
        $deviceToken = (string) session()->get(self::DEVICE_SESSION_KEY);
        $this->withCookie(PublicReporterIdentityService::COOKIE_NAME, $deviceToken);

        $this->post(route('public-reporter.identify'), [
            'phone' => '0812-3450-0187',
        ])
            ->assertRedirect(route('public-tickets.create'))
            ->assertSessionDoesntHaveErrors();
        $postIdentificationToken = (string) session()->get(self::DEVICE_SESSION_KEY);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{64}$/D', $postIdentificationToken);

        $reporter = User::query()->sole();
        $this->assertNotSame('', trim($reporter->name));
        $this->assertSame('6281234500187', $reporter->phone);
        $this->assertSame('6281234500187', $reporter->phone_normalized);
        $this->assertNull($reporter->email);
        $this->assertNull($reporter->password);
        $this->assertNull($reporter->identity);
        $this->assertTrue($reporter->is_active);
        $binding = HelpdeskReporterBinding::query()->sole();
        $this->assertSame($reporter->id, $binding->user_id);
        $this->assertSame('phone_input', $binding->verification_method);
        $this->assertSame([], $this->whatsAppGateway->messages);

        $this->assertTicketPage(
            $this->withCookie(PublicReporterIdentityService::COOKIE_NAME, $postIdentificationToken)
                ->get(route('public-tickets.create')),
            '0187',
        )->assertDontSee($reporter->name);

        $submissionToken = (string) session()->get(self::SUBMISSION_SESSION_KEY);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/D', $submissionToken);
        $this->post(
            route('public-tickets.store'),
            $this->validTicketPayload($submissionToken, ['title' => 'Tiket reporter otomatis']),
        )
            ->assertRedirect(route('public-tickets.create'))
            ->assertSessionHas('ticket_success');

        $ticket = Ticket::query()->sole();
        $history = TicketHistory::query()->sole();
        $this->assertSame($reporter->id, $ticket->owner_id);
        $this->assertSame($reporter->id, $history->user_id);
        $this->assertSame($ticket->id, $history->ticket_id);
        $this->assertTrue($history->ticket->is($ticket));
    }

    public function test_canonical_phone_variants_reuse_the_same_existing_reporter(): void
    {
        $reporter = $this->reporter('Nama Autoritatif', '0812-3450-0177');

        $this->get(route('public-tickets.create'))->assertOk();
        $this->post(route('public-reporter.identify'), [
            'phone' => '+62 812-3450-0177',
        ])
            ->assertRedirect(route('public-tickets.create'))
            ->assertSessionDoesntHaveErrors();

        $this->assertSame(1, User::query()->count());
        $this->assertSame($reporter->id, HelpdeskReporterBinding::query()->sole()->user_id);
        $this->assertSame('6281234500177', $reporter->fresh()->phone_normalized);
        $this->assertSame('Nama Autoritatif', $reporter->fresh()->name);
    }

    public function test_invalid_phone_is_rejected_without_creating_or_binding_a_reporter(): void
    {
        $this->get(route('public-tickets.create'))->assertOk();

        $this->post(route('public-reporter.identify'), ['phone' => 'nomor-wa-salah'])
            ->assertRedirect(route('public-tickets.create'))
            ->assertSessionHasErrors('phone');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('helpdesk_reporter_bindings', 0);
        $this->assertSame([], $this->whatsAppGateway->messages);
    }

    public function test_identity_honeypot_rejects_automated_submission_without_creating_a_reporter(): void
    {
        $this->post(route('public-reporter.identify'), [
            'phone' => '0812-3450-0166',
            'website' => 'https://spam.example.test',
        ])->assertSessionHasErrors('website');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('helpdesk_reporter_bindings', 0);
    }

    public function test_ambiguous_canonical_phone_is_rejected_without_guessing_an_owner(): void
    {
        $first = $this->reporter('Reporter Format Lokal', '0812-3450-0178');
        $second = $this->reporter('Reporter Format Internasional', '6281234500178');
        $this->assertSame($first->phone_normalized, $second->phone_normalized);

        $this->get(route('public-tickets.create'))->assertOk();
        $this->post(route('public-reporter.identify'), ['phone' => '+62 812-3450-0178'])
            ->assertRedirect(route('public-tickets.create'))
            ->assertSessionHasErrors('phone');

        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('helpdesk_reporter_bindings', 0);
    }

    public function test_inactive_and_soft_deleted_phone_matches_are_not_duplicated_or_bound(): void
    {
        $inactive = $this->reporter('Reporter Nonaktif', '0812-3450-0179');
        $inactive->update(['is_active' => false]);
        $deleted = $this->reporter('Reporter Terhapus', '0812-3450-0180');
        $deleted->delete();

        $this->get(route('public-tickets.create'))->assertOk();
        $this->post(route('public-reporter.identify'), ['phone' => '6281234500179'])
            ->assertRedirect(route('public-tickets.create'))
            ->assertSessionHasErrors('phone');
        $this->post(route('public-reporter.identify'), ['phone' => '6281234500180'])
            ->assertRedirect(route('public-tickets.create'))
            ->assertSessionHasErrors('phone');

        $this->assertSame(2, User::query()->withTrashed()->count());
        $this->assertDatabaseCount('helpdesk_reporter_bindings', 0);
    }

    public function test_reporter_binding_is_restored_from_the_device_cookie_without_session_identity(): void
    {
        $reporter = $this->reporter('Reporter Cookie', '6281234500181');

        $this->get(route('public-tickets.create'))->assertOk();
        $this->post(route('public-reporter.identify'), ['phone' => '0812-3450-0181'])
            ->assertRedirect(route('public-tickets.create'));
        $deviceToken = (string) session()->get(self::DEVICE_SESSION_KEY);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{64}$/D', $deviceToken);
        $this->assertSame($reporter->id, app(HelpdeskReporterIdentityService::class)->linkedUser(
            'public-helpdesk-form',
            'web',
            $deviceToken,
        )?->id);

        $this->flushSession();
        $this->assertNull(session()->get(self::DEVICE_SESSION_KEY));
        $response = $this->withCookie(PublicReporterIdentityService::COOKIE_NAME, $deviceToken)
            ->get(route('public-tickets.create'));

        $this->assertSame($deviceToken, session()->get(self::DEVICE_SESSION_KEY));
        $this->assertTicketPage($response, '0181')
            ->assertDontSee('Reporter Cookie');

        $this->assertSame($reporter->id, HelpdeskReporterBinding::query()->sole()->user_id);
        $this->assertTrue(Auth::guest());
    }

    public function test_web_reporter_binding_expires_server_side(): void
    {
        $reporter = $this->reporter('Reporter Lama Sekali', '6281234500189');
        $deviceToken = str_repeat('X', 64);
        $this->rememberReporter($reporter, $deviceToken, str_repeat('Y', 40));
        $binding = HelpdeskReporterBinding::query()->sole();
        $binding->update(['verified_at' => now()->subDays(366)]);

        $this->assertIdentifyPage($this->get(route('public-tickets.create')))
            ->assertDontSee('Reporter Lama Sekali');

        $this->assertNotNull($binding->fresh()->revoked_at);
    }

    public function test_bound_reporter_creates_an_open_ticket_and_history_as_the_owner(): void
    {
        $reporter = $this->reporter('Reporter Ticket', '6281234500102');
        $submissionToken = str_repeat('S', 40);
        $deviceToken = str_repeat('D', 64);
        $this->rememberReporter($reporter, $deviceToken, $submissionToken);

        $this->post(route('public-tickets.store'), $this->validTicketPayload($submissionToken, [
            'title' => 'Printer kasir tidak dapat mencetak',
            'description' => 'Printer berhenti merespons sejak pukul delapan pagi.',
            // Public payloads must never be able to choose their owner or status.
            'owner_id' => User::query()->create([
                'name' => 'Owner Palsu',
                'email' => null,
                'password' => null,
                'phone' => '6281234500199',
                'is_active' => true,
            ])->id,
            'ticket_statuses_id' => TicketStatus::CLOSED,
        ]))
            ->assertRedirect(route('public-tickets.create'))
            ->assertSessionHas('ticket_success');

        $ticket = Ticket::query()->sole();
        Notification::assertSentTo(
            $reporter,
            TicketSubmittedNotification::class,
            function (TicketSubmittedNotification $notification) use ($ticket): bool {
                $message = $notification->toWhatsapp($ticket->owner);

                return str_contains($message, '*Helpdesk*')
                    && str_contains($message, 'Laporan diterima')
                    && str_contains($message, 'HD-')
                    && ! str_contains($message, 'Nomor follow-up')
                    && ! str_contains($message, 'Koordinasi WA')
                    && str_contains($message, 'Printer kasir tidak dapat mencetak')
                    && ! str_contains($message, '/admin/tickets/')
                    && ! str_contains($message, 'Supported by IT Support');
            },
        );
        $this->assertSame($reporter->id, $ticket->owner_id);
        $this->assertSame(TicketStatus::OPEN, $ticket->ticket_statuses_id);
        $this->assertSame($this->unit->id, $ticket->unit_id);
        $this->assertSame($this->category->id, $ticket->problem_category_id);
        $this->assertSame($this->priority->id, $ticket->priority_id);
        $this->assertSame($this->businessEntity->id, $ticket->business_entities_id);
        $this->assertStringContainsString('Dilaporkan via Form Publik', $ticket->description);
        $this->assertStringNotContainsString('Reporter Ticket', $ticket->description);
        $this->assertStringNotContainsString('6281234500102', $ticket->description);

        $history = TicketHistory::query()->sole();
        $this->assertSame($ticket->id, $history->ticket_id);
        $this->assertSame($reporter->id, $history->user_id);
        $this->assertSame(TicketStatus::OPEN, $history->ticket_statuses_id);
        $this->assertNull(session()->get(self::SUBMISSION_SESSION_KEY));
        $this->assertTrue(Auth::guest());

        // A retry carrying the exact same one-time token must replay the
        // durable result instead of creating a second ticket/history.
        $this->withCookie(PublicReporterIdentityService::COOKIE_NAME, $deviceToken)
            ->withSession([
                self::DEVICE_SESSION_KEY => $deviceToken,
                self::SUBMISSION_SESSION_KEY => $submissionToken,
            ])
            ->post(route('public-tickets.store'), $this->validTicketPayload($submissionToken, [
                'title' => 'Printer kasir tidak dapat mencetak',
                'description' => 'Printer berhenti merespons sejak pukul delapan pagi.',
            ]))
            ->assertRedirect(route('public-tickets.create'));

        $this->assertSame(1, Ticket::query()->count());
        $this->assertSame(1, TicketHistory::query()->count());
        $this->assertDatabaseCount('mcp_ticket_creation_requests', 1);
    }

    public function test_public_submission_rejects_a_category_from_another_unit(): void
    {
        $reporter = $this->reporter('Reporter Validasi', '6281234500103');
        $submissionToken = str_repeat('T', 40);
        $this->rememberReporter($reporter, str_repeat('E', 64), $submissionToken);

        $this->post(route('public-tickets.store'), $this->validTicketPayload($submissionToken, [
            'problem_category_id' => $this->otherCategory->id,
        ]))
            ->assertSessionHasErrors([
                'problem_category_id' => 'Kategori tidak sesuai dengan divisi yang dipilih.',
            ]);

        $this->assertDatabaseCount('tickets', 0);
        $this->assertDatabaseCount('ticket_histories', 0);
        $this->assertSame($submissionToken, session()->get(self::SUBMISSION_SESSION_KEY));
    }

    public function test_change_reporter_revokes_the_binding_and_returns_to_phone_identification(): void
    {
        $reporter = $this->reporter('Reporter Diganti', '6281234500104');
        $deviceToken = str_repeat('F', 64);
        $this->rememberReporter($reporter, $deviceToken, str_repeat('U', 40));
        $binding = HelpdeskReporterBinding::query()->sole();

        $this->post(route('public-reporter.change'))
            ->assertRedirect(route('public-tickets.create'))
            ->assertSessionHas('status', 'Nomor WhatsApp di perangkat ini sudah dilepas.')
            ->assertCookieExpired(PublicReporterIdentityService::COOKIE_NAME);

        $this->assertNotNull($binding->fresh()->revoked_at);
        $this->assertNull(session()->get(self::DEVICE_SESSION_KEY));
        $this->assertNull(session()->get(self::SUBMISSION_SESSION_KEY));
        $this->assertDatabaseHas('users', [
            'id' => $reporter->id,
            'deleted_at' => null,
        ]);

        // Even if an old browser resends the bearer token, a revoked binding
        // must no longer reveal the previous reporter.
        $this->assertIdentifyPage($this->get(route('public-tickets.create')))
            ->assertDontSee('Reporter Diganti');
    }

    public function test_change_reporter_revokes_distinct_session_and_cookie_bindings(): void
    {
        $reporter = $this->reporter('Reporter Token Ganda', '6281234500167');
        $sessionToken = str_repeat('J', 64);
        $cookieToken = str_repeat('K', 64);
        $identities = app(HelpdeskReporterIdentityService::class);

        foreach ([$sessionToken, $cookieToken] as $token) {
            $this->assertTrue($identities->bind(
                'public-helpdesk-form',
                'web',
                $token,
                $reporter,
                'phone_input',
            ));
        }

        $this->withSession([
            self::DEVICE_SESSION_KEY => $sessionToken,
            self::SUBMISSION_SESSION_KEY => str_repeat('Y', 40),
        ])->withCookie(PublicReporterIdentityService::COOKIE_NAME, $cookieToken)
            ->post(route('public-reporter.change'))
            ->assertRedirect(route('public-tickets.create'))
            ->assertCookieExpired(PublicReporterIdentityService::COOKIE_NAME);

        $this->assertSame(2, HelpdeskReporterBinding::query()->whereNotNull('revoked_at')->count());
        $this->assertNull(session()->get(self::DEVICE_SESSION_KEY));
        $this->assertNull(session()->get(self::SUBMISSION_SESSION_KEY));
    }

    public function test_submission_service_restores_the_previous_auth_user_after_success_and_failure(): void
    {
        $reporter = $this->reporter('Reporter Service', '6281234500105');
        $sentinel = $this->reporter('Authenticated Sentinel', '6281234500106');
        $service = app(PublicTicketSubmissionService::class);

        Auth::setUser($sentinel);
        $created = $service->create($reporter, $this->validTicketPayload(str_repeat('V', 40), [
            'title' => 'Auth context success',
        ]));

        $this->assertSame($sentinel->id, Auth::id());
        $this->assertDatabaseHas('ticket_histories', [
            'ticket_id' => $created->id,
            'user_id' => $reporter->id,
        ]);

        Ticket::creating(static function (): void {
            throw new RuntimeException('forced public ticket failure');
        });

        try {
            $service->create($reporter, $this->validTicketPayload(str_repeat('W', 40), [
                'title' => 'Auth context failure',
            ]));
            $this->fail('The forced ticket creation failure was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced public ticket failure', $exception->getMessage());
        }

        $this->assertSame($sentinel->id, Auth::id());
        $this->assertSame(1, Ticket::query()->count());
        $this->assertSame(1, TicketHistory::query()->count());
    }

    public function test_post_commit_hydration_failure_keeps_attachments_and_replays_the_committed_ticket(): void
    {
        $reporter = $this->reporter('Reporter Post Commit', '6281234500191');
        $service = app(PublicTicketSubmissionService::class);
        $submissionToken = str_repeat('X', 40);
        $payload = $this->validTicketPayload($submissionToken, [
            'title' => 'Hydration gagal setelah commit',
        ]);
        $attachment = UploadedFile::fake()->createWithContent(
            'diagnostic.txt',
            'diagnostic attachment that must survive a post-commit exception',
        );
        $throwOnHydration = false;
        $hydrationExceptionThrown = false;

        DB::listen(function (QueryExecuted $query) use (&$throwOnHydration, &$hydrationExceptionThrown): void {
            $sql = strtolower($query->sql);

            if (str_starts_with(ltrim($sql), 'update')
                && str_contains($sql, 'mcp_ticket_creation_requests')
                && in_array('completed', $query->bindings, true)) {
                $throwOnHydration = true;

                return;
            }

            if ($throwOnHydration && ! $hydrationExceptionThrown
                && str_starts_with(ltrim($sql), 'select')
                && str_contains($sql, 'priorities')) {
                $hydrationExceptionThrown = true;

                throw new RuntimeException('forced post-commit hydration failure');
            }
        });

        $created = $service->create($reporter, $payload, [$attachment], $submissionToken);

        $this->assertTrue($hydrationExceptionThrown);
        $ticket = Ticket::query()->sole();
        $this->assertSame($created->id, $ticket->id);
        $attachmentPath = $ticket->supporting_attachments[0] ?? null;
        $this->assertIsString($attachmentPath);
        Storage::disk('public')->assertExists($attachmentPath);
        $this->assertDatabaseHas('mcp_ticket_creation_requests', [
            'status' => 'completed',
        ]);

        $replayed = $service->create($reporter, $payload, [$attachment], $submissionToken);

        $this->assertSame($ticket->id, $replayed->id);
        $this->assertSame(1, Ticket::query()->count());
        $this->assertSame(1, TicketHistory::query()->count());
        $this->assertDatabaseCount('mcp_ticket_creation_requests', 1);
        Storage::disk('public')->assertExists($attachmentPath);
    }

    private function assertIdentifyPage(TestResponse $response): TestResponse
    {
        return $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('PublicTickets/Create')
                ->where('screen', 'identify')
                ->where('reporter', null)
                ->has('urls.identify')
                ->has('urls.store')
                ->has('urls.login')
            );
    }

    private function assertTicketPage(TestResponse $response, ?string $phoneNeedle = null): TestResponse
    {
        return $response
            ->assertOk()
            ->assertInertia(function (Assert $page) use ($phoneNeedle) {
                $page
                    ->component('PublicTickets/Create')
                    ->where('screen', 'ticket')
                    ->has('reporter.phone')
                    ->missing('reporter.name')
                    ->missing('reporter.email')
                    ->has('submissionToken')
                    ->has('options.units')
                    ->has('options.problem_categories')
                    ->has('options.priorities')
                    ->has('options.business_entities');

                if ($phoneNeedle !== null) {
                    $page->where(
                        'reporter.phone',
                        fn ($phone): bool => str_contains((string) $phone, $phoneNeedle),
                    );
                }
            });
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

    private function rememberReporter(
        User $reporter,
        string $deviceToken,
        string $submissionToken,
    ): void {
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
}
