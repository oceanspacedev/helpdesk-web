<?php

namespace Tests\Feature;

use App\Models\BusinessEntity;
use App\Models\Priority;
use App\Models\ProblemCategory;
use App\Models\Ticket;
use App\Models\User;
use App\Services\EmployeeService;
use App\Services\Integrations\HelpdeskIntakeSession;
use App\Services\Integrations\HelpdeskTicketCreationService;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\Concerns\CreatesHelpdeskIntegrationSchema;
use Tests\TestCase;

class HelpdeskTicketCreationServiceTest extends TestCase
{
    use CreatesHelpdeskIntegrationSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTestSchema();
        Cache::flush();
        Notification::fake();
    }

    public function test_ticket_creation_restores_auth_context_after_success_and_failure(): void
    {
        $owner = $this->helpdeskUser();
        $sentinel = User::query()->create([
            'name' => 'Outer Process User',
            'email' => null,
            'password' => null,
            'phone' => '6281234567001',
            'is_active' => true,
        ]);
        $service = app(HelpdeskTicketCreationService::class);

        Auth::setUser($sentinel);
        $created = $service->create($this->payload($owner, 'auth-scope-sentinel', 'Auth scoped sentinel'));

        $this->assertTrue((bool) ($created['ok'] ?? false));
        $this->assertSame($sentinel->id, Auth::id());
        $this->assertDatabaseHas('ticket_histories', ['ticket_id' => 1, 'user_id' => $owner->id]);

        Auth::forgetUser();
        $createdAsGuest = $service->create($this->payload($owner, 'auth-scope-guest', 'Auth scoped guest'));

        $this->assertTrue((bool) ($createdAsGuest['ok'] ?? false));
        $this->assertTrue(Auth::guest());

        Ticket::creating(static function (): void {
            throw new RuntimeException('forced ticket creation failure');
        });
        Auth::setUser($sentinel);

        try {
            $service->create($this->payload($owner, 'auth-scope-failure', 'Auth scoped failure'));
            $this->fail('The forced ticket creation exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced ticket creation failure', $exception->getMessage());
        }

        $this->assertSame($sentinel->id, Auth::id());
    }

    public function test_ticket_creation_fails_closed_while_reporter_is_locked(): void
    {
        $phone = '6281234567890';
        $lock = Cache::lock((string) PhoneNumber::reporterWriteLockKey($phone), 30);
        $this->assertTrue($lock->get());

        try {
            $result = app(HelpdeskTicketCreationService::class)->create([
                'idempotency_key' => 'helpdesk:create:busy-reporter',
                'actor' => [
                    'phone' => $phone,
                    'is_verified' => true,
                ],
                'ticket_data' => [
                    'title' => 'Tidak boleh berjalan paralel',
                    'description' => 'Uji lock identitas pelapor.',
                    'consent_to_create' => true,
                ],
            ]);

            $this->assertSame('in_progress', $result['status']);
            $this->assertSame('HELPDESK_REPORTER_BUSY', data_get($result, 'error.code'));
            $this->assertSame(0, Ticket::count());
        } finally {
            $lock->release();
        }
    }

    public function test_ticket_creation_is_durably_idempotent(): void
    {
        $owner = $this->helpdeskUser();
        $service = app(HelpdeskTicketCreationService::class);
        $payload = $this->payload($owner, 'helpdesk:create:duplicate', 'Printer kasir error');

        $first = $service->create($payload);
        Cache::flush();
        $replayed = $service->create($payload);

        $this->assertTrue((bool) ($first['ok'] ?? false));
        $this->assertSame(1, data_get($first, 'data.ticket.ticket_id'));
        $this->assertSame(1, data_get($replayed, 'data.ticket.ticket_id'));
        $this->assertTrue((bool) data_get($replayed, 'data.idempotency.replayed'));
        $this->assertSame(1, Ticket::count());

        $payload['ticket_data']['title'] = 'Payload berubah';
        $conflict = $service->create($payload);

        $this->assertSame('idempotency_conflict', $conflict['status']);
        $this->assertSame('IDEMPOTENCY_KEY_REUSED', data_get($conflict, 'error.code'));
        $this->assertSame(1, Ticket::count());
    }

    public function test_missing_account_can_be_created_then_retried_with_the_same_idempotency_key(): void
    {
        $owner = $this->helpdeskUser();
        $payload = $this->payload($owner, 'helpdesk:create:account-prerequisite', 'Akun dibuat lebih dahulu');
        $phone = (string) $owner->phone;
        $owner->forceFill([
            'phone' => '6281234567999',
            'phone_normalized' => '6281234567999',
        ])->saveQuietly();

        $employees = $this->mock(EmployeeService::class);
        $employees->shouldReceive('findAllByPhone')->once()->with($phone)->andReturn([]);
        $service = app(HelpdeskTicketCreationService::class);

        $accountRequired = $service->create($payload);

        $this->assertSame('account_required', $accountRequired['status']);
        $this->assertSame('HELPDESK_REPORTER_ACCOUNT_REQUIRED', data_get($accountRequired, 'error.code'));
        $this->assertDatabaseCount('mcp_ticket_creation_requests', 0);
        $this->assertSame(0, Ticket::count());

        User::query()->create([
            'name' => 'Reporter Baru',
            'email' => null,
            'password' => null,
            'phone' => $phone,
            'is_active' => true,
        ]);

        $created = $service->create($payload);

        $this->assertTrue($created['ok']);
        $this->assertSame('ticket_created', $created['status']);
        $this->assertSame(1, Ticket::count());
        $this->assertDatabaseCount('mcp_ticket_creation_requests', 1);
    }

    public function test_local_durable_replay_is_scoped_to_the_originating_client(): void
    {
        $owner = $this->helpdeskUser();
        $intakeId = 'hdi_'.str_repeat('a', 40);
        $externalUserId = 'direct-client';
        $clientA = 'local-configured:'.hash('sha256', 'client-a');
        $clientB = 'local-configured:'.hash('sha256', 'client-b');
        $contextFingerprint = hash('sha256', "mcp\0{$externalUserId}");
        $payload = $this->payload(
            $owner,
            "intake:{$intakeId}:{$contextFingerprint}",
            'Isolated local replay',
        );
        $payload['client_id'] = $clientA;

        $created = app(HelpdeskTicketCreationService::class)->create($payload);
        $this->assertTrue($created['ok']);

        Cache::flush();
        $session = app(HelpdeskIntakeSession::class);
        $context = [
            'intake_id' => $intakeId,
            'external_user_id' => $externalUserId,
        ];

        $crossClient = $session->handle('', '', context: $context + ['client_id' => $clientB]);
        $this->assertSame('invalid_session', $crossClient['status']);

        $originatingClient = $session->handle('', '', context: $context + ['client_id' => $clientA]);
        $this->assertSame('ticket_created', $originatingClient['status']);
        $this->assertTrue($originatingClient['replayed']);
        $this->assertSame(1, data_get($originatingClient, 'ticket.id'));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(User $owner, string $key, string $title): array
    {
        $category = ProblemCategory::query()->first() ?? $this->problemCategory();
        $priority = Priority::query()->firstOrCreate(
            ['id' => Priority::MEDIUM],
            ['name' => 'Medium'],
        );
        $businessEntity = BusinessEntity::query()->firstOrCreate(['name' => 'Complete Selular']);

        return [
            'idempotency_key' => $key,
            'actor' => [
                'phone' => $owner->phone,
                'is_verified' => true,
                'channel' => 'mcp',
                'integration_source' => 'Helpdesk MCP',
            ],
            'ticket_data' => [
                'title' => $title,
                'description' => 'Memastikan creation service MCP aman dan idempoten.',
                'unit_id' => $category->unit_id,
                'problem_category_id' => $category->id,
                'priority_id' => $priority->id,
                'business_entities_id' => $businessEntity->id,
                'consent_to_create' => true,
            ],
        ];
    }
}
