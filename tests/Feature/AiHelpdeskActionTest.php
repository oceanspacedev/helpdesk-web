<?php

namespace Tests\Feature;

use App\Models\BusinessEntity;
use App\Models\Priority;
use App\Models\ProblemCategory;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AiHelpdeskActionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createTestSchema();
        Cache::flush();
        Notification::fake();
        config()->set('services.ita_helpdesk.token', 'secret-ita-token');
    }

    public function test_ita_can_create_a_helpdesk_ticket_for_a_registered_whatsapp_actor(): void
    {
        $owner = $this->helpdeskUser();
        $category = $this->problemCategory();
        $priority = Priority::create(['id' => Priority::MEDIUM, 'name' => 'Medium']);
        $businessEntity = BusinessEntity::create(['name' => 'Complete Selular']);

        $response = $this->withToken('secret-ita-token')->postJson('/api/integrations/ai/helpdesk/actions', [
            'request_id' => 'trace-create-001',
            'idempotency_key' => 'ita:create:001',
            'action' => 'helpdesk.create_ticket',
            'actor' => [
                'name' => 'Apri',
                'phone' => '0812-3456-7890',
                'is_verified' => true,
                'channel' => 'whatsapp',
            ],
            'ticket_data' => [
                'issue_summary' => 'Tidak bisa login email',
                'affected_system' => 'Email',
                'impact' => 'Tidak bisa kirim invoice',
                'description' => 'Muncul invalid credentials setelah reset password.',
                'urgency' => 'normal',
                'problem_category_id' => $category->id,
                'priority_id' => $priority->id,
                'business_entities_id' => $businessEntity->id,
                'consent_to_create' => true,
            ],
            'attachments' => [
                [
                    'filename' => 'screenshot.png',
                    'mime_type' => 'image/png',
                    'url' => 'https://storage.example.test/screenshot.png',
                ],
            ],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('result_status', 'ticket_created')
            ->assertJsonPath('data.ticket.ticket_id', 1)
            ->assertJsonPath('data.ticket.status', 'Open');

        $this->assertDatabaseHas('tickets', [
            'id' => 1,
            'owner_id' => $owner->id,
            'unit_id' => $category->unit_id,
            'problem_category_id' => $category->id,
            'priority_id' => $priority->id,
            'business_entities_id' => $businessEntity->id,
            'ticket_statuses_id' => TicketStatus::OPEN,
        ]);
        $this->assertDatabaseHas('ticket_histories', [
            'ticket_id' => 1,
            'ticket_statuses_id' => TicketStatus::OPEN,
            'user_id' => $owner->id,
        ]);

        $ticket = Ticket::firstOrFail();
        $this->assertStringContainsString('Dilaporkan via ITA', $ticket->description);
        $this->assertStringContainsString('Tidak bisa kirim invoice', $ticket->description);
        $this->assertStringContainsString('https://storage.example.test/screenshot.png', $ticket->description);
    }

    public function test_ita_create_ticket_replays_the_same_idempotency_key_without_duplicate_tickets(): void
    {
        $this->helpdeskUser();
        $category = $this->problemCategory();
        Priority::create(['id' => Priority::MEDIUM, 'name' => 'Medium']);

        $payload = [
            'request_id' => 'trace-create-002',
            'idempotency_key' => 'ita:create:duplicate',
            'action' => 'helpdesk.create_ticket',
            'actor' => [
                'phone' => '6281234567890',
                'is_verified' => true,
            ],
            'ticket_data' => [
                'issue_summary' => 'Printer kasir error',
                'affected_system' => 'Printer',
                'impact' => 'Tidak bisa cetak struk',
                'problem_category_id' => $category->id,
                'consent_to_create' => true,
            ],
        ];

        $first = $this->withToken('secret-ita-token')->postJson('/api/integrations/ai/helpdesk/actions', $payload);
        $second = $this->withToken('secret-ita-token')->postJson('/api/integrations/ai/helpdesk/actions', $payload);

        $first->assertOk()->assertJsonPath('data.ticket.ticket_id', 1);
        $second
            ->assertOk()
            ->assertJsonPath('data.ticket.ticket_id', 1)
            ->assertJsonPath('data.idempotency.replayed', true);

        $this->assertSame(1, Ticket::count());
    }

    public function test_ita_can_get_comment_and_close_an_existing_ticket(): void
    {
        $owner = $this->helpdeskUser();
        $category = $this->problemCategory();
        Priority::create(['id' => Priority::MEDIUM, 'name' => 'Medium']);
        Auth::setUser($owner);
        $ticket = Ticket::create([
            'priority_id' => Priority::MEDIUM,
            'unit_id' => $category->unit_id,
            'owner_id' => $owner->id,
            'problem_category_id' => $category->id,
            'title' => 'Email error',
            'description' => 'Tidak bisa login.',
            'ticket_statuses_id' => TicketStatus::OPEN,
        ]);

        $this->withToken('secret-ita-token')->postJson('/api/integrations/ai/helpdesk/actions', [
            'request_id' => 'trace-get-001',
            'idempotency_key' => 'ita:get:001',
            'action' => 'helpdesk.get_ticket',
            'actor' => [
                'phone' => '6281234567890',
                'is_verified' => true,
            ],
            'ticket_ref' => ['ticket_id' => (string) $ticket->id],
        ])
            ->assertOk()
            ->assertJsonPath('result_status', 'found')
            ->assertJsonPath('data.ticket.ticket_id', $ticket->id);

        $this->withToken('secret-ita-token')->postJson('/api/integrations/ai/helpdesk/actions', [
            'request_id' => 'trace-comment-001',
            'idempotency_key' => 'ita:comment:001',
            'action' => 'helpdesk.add_comment',
            'actor' => [
                'phone' => '6281234567890',
                'is_verified' => true,
            ],
            'ticket_ref' => ['ticket_id' => (string) $ticket->id],
            'ticket_data' => ['description' => 'Tambahan info: error muncul jam 09.00.'],
        ])
            ->assertOk()
            ->assertJsonPath('result_status', 'comment_added');

        $this->assertDatabaseHas('comments', [
            'tiket_id' => $ticket->id,
            'user_id' => $owner->id,
            'comment' => 'Tambahan info: error muncul jam 09.00.',
        ]);

        $this->withToken('secret-ita-token')->postJson('/api/integrations/ai/helpdesk/actions', [
            'request_id' => 'trace-close-001',
            'idempotency_key' => 'ita:close:001',
            'action' => 'helpdesk.close_ticket',
            'actor' => [
                'phone' => '6281234567890',
                'is_verified' => true,
            ],
            'ticket_ref' => ['ticket_id' => (string) $ticket->id],
            'ticket_data' => ['consent_to_close' => true],
        ])
            ->assertOk()
            ->assertJsonPath('result_status', 'closed')
            ->assertJsonPath('data.ticket.status', 'Closed');

        $this->assertDatabaseHas('tickets', [
            'id' => $ticket->id,
            'ticket_statuses_id' => TicketStatus::CLOSED,
        ]);
        $this->assertNotNull($ticket->fresh()->solved_at);
    }

    public function test_ita_rejects_unregistered_whatsapp_actors(): void
    {
        $this->problemCategory();
        Priority::create(['id' => Priority::MEDIUM, 'name' => 'Medium']);

        $this->withToken('secret-ita-token')->postJson('/api/integrations/ai/helpdesk/actions', [
            'request_id' => 'trace-unregistered-001',
            'idempotency_key' => 'ita:create:unregistered',
            'action' => 'helpdesk.create_ticket',
            'actor' => [
                'phone' => '6280000000000',
                'is_verified' => true,
            ],
            'ticket_data' => [
                'issue_summary' => 'Email error',
                'affected_system' => 'Email',
                'impact' => 'Tidak bisa kerja',
                'consent_to_create' => true,
            ],
        ])
            ->assertForbidden()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('result_status', 'unauthorized');

        $this->assertSame(0, Ticket::count());
    }

    private function helpdeskUser(): User
    {
        return User::create([
            'name' => 'Apri',
            'email' => 'apri@example.test',
            'password' => 'secret',
            'phone' => '6281234567890',
            'is_active' => true,
        ]);
    }

    private function problemCategory(): ProblemCategory
    {
        $unit = Unit::create(['name' => 'IT']);

        return ProblemCategory::create([
            'id' => 50,
            'unit_id' => $unit->id,
            'name' => 'Akses Akun',
        ]);
    }

    private function createTestSchema(): void
    {
        foreach ([
            'comments',
            'ticket_histories',
            'tickets',
            'problem_categories',
            'units',
            'business_entities',
            'priorities',
            'ticket_statuses',
            'model_has_roles',
            'roles',
            'activity_log',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('activity_log', function (Blueprint $table): void {
            $table->id();
            $table->string('log_name')->nullable();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->nullableMorphs('causer', 'causer');
            $table->string('event')->nullable();
            $table->json('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->string('remember_token')->nullable();
            $table->string('identity')->nullable();
            $table->string('phone', 20)->nullable()->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name')->default('web');
            $table->timestamps();
        });

        Schema::create('model_has_roles', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
        });

        Schema::create('ticket_statuses', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('priorities', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        Schema::create('business_entities', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('units', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('problem_categories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('unit_id');
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tickets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('priority_id');
            $table->unsignedBigInteger('unit_id');
            $table->unsignedBigInteger('owner_id');
            $table->unsignedBigInteger('problem_category_id');
            $table->string('title');
            $table->text('description');
            $table->json('supporting_attachments')->nullable();
            $table->unsignedBigInteger('business_entities_id')->nullable();
            $table->unsignedBigInteger('ticket_statuses_id');
            $table->unsignedBigInteger('responsible_id')->nullable();
            $table->timestamps();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('solved_at')->nullable();
            $table->softDeletes();
        });

        Schema::create('ticket_histories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('ticket_id');
            $table->unsignedBigInteger('ticket_statuses_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
        });

        Schema::create('comments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tiket_id');
            $table->unsignedBigInteger('user_id');
            $table->text('comment');
            $table->string('attachments')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        TicketStatus::create(['id' => TicketStatus::OPEN, 'name' => 'Open']);
        TicketStatus::create(['id' => TicketStatus::IN_PROGRESS, 'name' => 'In Progress']);
        TicketStatus::create(['id' => TicketStatus::CANCEL, 'name' => 'Cancel']);
        TicketStatus::create(['id' => TicketStatus::CLOSED, 'name' => 'Closed']);
    }
}
