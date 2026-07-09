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

class WhatsappHelpdeskActionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createTestSchema();
        Cache::flush();
        Notification::fake();
    }

    public function test_ita_can_create_a_helpdesk_ticket_for_a_registered_whatsapp_actor(): void
    {
        $owner = $this->helpdeskUser();
        $category = $this->problemCategory();
        $priority = Priority::create(['id' => Priority::MEDIUM, 'name' => 'Medium']);
        $businessEntity = BusinessEntity::create(['name' => 'Complete Selular']);

        $response = $this->postJson('/api/integrations/whatsapp/helpdesk/actions', [
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
                'unit_id' => $category->unit_id,
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
            ->assertJsonPath('data.ticket.status', 'Open')
            ->assertJsonPath('data.ticket.supporting_attachments.0', 'https://storage.example.test/screenshot.png');

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
        $this->assertSame(['https://storage.example.test/screenshot.png'], $ticket->supporting_attachments);
    }

    public function test_ita_create_ticket_replays_the_same_idempotency_key_without_duplicate_tickets(): void
    {
        $this->helpdeskUser();
        $category = $this->problemCategory();
        $priority = Priority::create(['id' => Priority::MEDIUM, 'name' => 'Medium']);
        $businessEntity = BusinessEntity::create(['name' => 'Complete Selular']);

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
                'unit_id' => $category->unit_id,
                'problem_category_id' => $category->id,
                'priority_id' => $priority->id,
                'business_entities_id' => $businessEntity->id,
                'consent_to_create' => true,
            ],
        ];

        $first = $this->postJson('/api/integrations/whatsapp/helpdesk/actions', $payload);
        $second = $this->postJson('/api/integrations/whatsapp/helpdesk/actions', $payload);

        $first->assertOk()->assertJsonPath('data.ticket.ticket_id', 1);
        $second
            ->assertOk()
            ->assertJsonPath('data.ticket.ticket_id', 1)
            ->assertJsonPath('data.idempotency.replayed', true);

        $this->assertSame(1, Ticket::count());
    }

    public function test_ita_matches_helpdesk_user_by_phone_before_email_metadata(): void
    {
        $phoneOwner = $this->helpdeskUser();
        $emailOwner = User::create([
            'name' => 'User Dengan Email Metadata',
            'email' => 'metadata@example.test',
            'password' => 'secret',
            'phone' => '6289999999999',
            'is_active' => true,
        ]);
        $category = $this->problemCategory();
        $priority = Priority::create(['id' => Priority::MEDIUM, 'name' => 'Medium']);
        $businessEntity = BusinessEntity::create(['name' => 'Complete Selular']);

        $this->postJson('/api/integrations/whatsapp/helpdesk/actions', [
            'request_id' => 'trace-phone-first-001',
            'idempotency_key' => 'ita:create:phone-first',
            'action' => 'helpdesk.create_ticket',
            'actor' => [
                'name' => 'Apri',
                'phone' => '0812-3456-7890',
                'email' => $emailOwner->email,
                'is_verified' => true,
            ],
            'ticket_data' => [
                'issue_summary' => 'VPN error',
                'affected_system' => 'VPN',
                'impact' => 'Tidak bisa akses sistem kantor',
                'unit_id' => $category->unit_id,
                'problem_category_id' => $category->id,
                'priority_id' => $priority->id,
                'business_entities_id' => $businessEntity->id,
                'consent_to_create' => true,
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.ticket.owner.id', $phoneOwner->id);

        $this->assertDatabaseHas('tickets', [
            'id' => 1,
            'owner_id' => $phoneOwner->id,
        ]);
        $this->assertDatabaseMissing('tickets', [
            'owner_id' => $emailOwner->id,
        ]);
        $this->assertSame(2, User::count());
    }

    public function test_ita_can_get_and_comment_on_an_existing_ticket(): void
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

        $this->postJson('/api/integrations/whatsapp/helpdesk/actions', [
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

        $this->postJson('/api/integrations/whatsapp/helpdesk/actions', [
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

        $this->assertDatabaseHas('tickets', [
            'id' => $ticket->id,
            'ticket_statuses_id' => TicketStatus::OPEN,
        ]);
        $this->assertNull($ticket->fresh()->solved_at);
    }

    public function test_ita_rejects_close_ticket_action_from_reporter_contract(): void
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

        $this->postJson('/api/integrations/whatsapp/helpdesk/actions', [
            'request_id' => 'trace-close-reporter-001',
            'idempotency_key' => 'ita:close:reporter',
            'action' => 'helpdesk.close_ticket',
            'actor' => [
                'phone' => '6281234567890',
                'is_verified' => true,
            ],
            'ticket_ref' => ['ticket_id' => (string) $ticket->id],
            'ticket_data' => ['consent_to_close' => true],
        ])
            ->assertStatus(422)
            ->assertJsonPath('result_status', 'validation_error')
            ->assertJsonPath('error.code', 'UNSUPPORTED_HELPDESK_ACTION');

        $this->assertDatabaseHas('tickets', [
            'id' => $ticket->id,
            'ticket_statuses_id' => TicketStatus::OPEN,
        ]);
        $this->assertNull($ticket->fresh()->solved_at);
    }

    public function test_ita_rejects_close_ticket_action_even_for_responsible_technician_contract(): void
    {
        $owner = $this->helpdeskUser();
        $technician = $this->technicianUser();
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
            'ticket_statuses_id' => TicketStatus::IN_PROGRESS,
        ]);
        $ticket->forceFill([
            'responsible_id' => $technician->id,
        ])->saveQuietly();

        $this->postJson('/api/integrations/whatsapp/helpdesk/actions', [
            'request_id' => 'trace-close-tech-001',
            'idempotency_key' => 'ita:close:technician',
            'action' => 'helpdesk.close_ticket',
            'actor' => [
                'phone' => '6287777777777',
                'is_verified' => true,
            ],
            'ticket_ref' => ['ticket_id' => (string) $ticket->id],
            'ticket_data' => ['consent_to_close' => true],
        ])
            ->assertStatus(422)
            ->assertJsonPath('result_status', 'validation_error')
            ->assertJsonPath('error.code', 'UNSUPPORTED_HELPDESK_ACTION');

        $this->assertDatabaseHas('tickets', [
            'id' => $ticket->id,
            'responsible_id' => $technician->id,
            'ticket_statuses_id' => TicketStatus::IN_PROGRESS,
        ]);
        $this->assertNull($ticket->fresh()->solved_at);
    }

    public function test_ita_can_auto_register_internal_whatsapp_reporters_without_synthetic_email(): void
    {
        $category = $this->problemCategory();
        $priority = Priority::create(['id' => Priority::MEDIUM, 'name' => 'Medium']);
        $businessEntity = BusinessEntity::create(['name' => 'Complete Selular']);

        $create = $this->postJson('/api/integrations/whatsapp/helpdesk/actions', [
            'request_id' => 'trace-external-001',
            'idempotency_key' => 'ita:create:external',
            'action' => 'helpdesk.create_ticket',
            'actor' => [
                'name' => 'Budi Outlet Depok',
                'phone' => '6280000000000',
                'is_verified' => true,
            ],
            'ticket_data' => [
                'issue_summary' => 'Email error',
                'affected_system' => 'Email',
                'impact' => 'Tidak bisa kerja',
                'unit_id' => $category->unit_id,
                'problem_category_id' => $category->id,
                'priority_id' => $priority->id,
                'business_entities_id' => $businessEntity->id,
                'consent_to_create' => true,
            ],
        ]);

        $create
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('result_status', 'ticket_created')
            ->assertJsonPath('data.ticket.ticket_id', 1)
            ->assertJsonPath('data.ticket.owner.needs_profile_completion', true);

        $externalUser = User::where('phone', '6280000000000')->firstOrFail();

        $this->assertSame('Budi Outlet Depok', $externalUser->name);
        $this->assertNull($externalUser->email);
        $this->assertNull($externalUser->password);
        $this->assertNull($externalUser->identity);
        $this->assertDatabaseHas('tickets', [
            'id' => 1,
            'owner_id' => $externalUser->id,
            'ticket_statuses_id' => TicketStatus::OPEN,
        ]);

        $this->postJson('/api/integrations/whatsapp/helpdesk/actions', [
            'request_id' => 'trace-external-comment-001',
            'idempotency_key' => 'ita:comment:external',
            'action' => 'helpdesk.add_comment',
            'actor' => [
                'phone' => '0800-0000-0000',
                'is_verified' => true,
            ],
            'ticket_ref' => ['ticket_id' => '1'],
            'ticket_data' => ['description' => 'Tambahan dari pelapor eksternal.'],
        ])
            ->assertOk()
            ->assertJsonPath('result_status', 'comment_added');

        $this->assertSame(1, Ticket::count());
        $this->assertSame(1, User::count());
        $this->assertDatabaseHas('comments', [
            'tiket_id' => 1,
            'user_id' => $externalUser->id,
            'comment' => 'Tambahan dari pelapor eksternal.',
        ]);
    }

    public function test_ita_rejects_lid_identity_for_helpdesk_actor_phone(): void
    {
        $category = $this->problemCategory();
        $priority = Priority::create(['id' => Priority::MEDIUM, 'name' => 'Medium']);
        $businessEntity = BusinessEntity::create(['name' => 'Complete Selular']);

        $this->postJson('/api/integrations/whatsapp/helpdesk/actions', [
            'request_id' => 'trace-lid-create-001',
            'idempotency_key' => 'ita:create:lid-only',
            'action' => 'helpdesk.create_ticket',
            'actor' => [
                'name' => 'Pelapor LID',
                'phone' => '123456789012345@lid',
                'identifier' => '123456789012345@lid',
                'sender_key' => 'default:123456789012345@lid',
                'is_verified' => true,
            ],
            'ticket_data' => [
                'issue_summary' => 'Email error',
                'affected_system' => 'Email',
                'impact' => 'Tidak bisa kerja',
                'unit_id' => $category->unit_id,
                'problem_category_id' => $category->id,
                'priority_id' => $priority->id,
                'business_entities_id' => $businessEntity->id,
                'consent_to_create' => true,
            ],
        ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'HELPDESK_ACTOR_NOT_REGISTERED');

        $this->assertSame(0, User::count());
        $this->assertSame(0, Ticket::count());
    }

    public function test_ita_master_data_endpoint_returns_web_form_options(): void
    {
        $category = $this->problemCategory();
        Priority::create(['id' => Priority::MEDIUM, 'name' => 'Medium']);
        $businessEntity = BusinessEntity::create(['name' => 'Complete Selular']);

        $this->getJson('/api/integrations/whatsapp/helpdesk/master-data')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.form_options.units.0.name', 'IT')
            ->assertJsonPath('data.form_options.problem_categories.0.name', $category->name)
            ->assertJsonPath('data.form_options.priorities.0.name', 'Medium')
            ->assertJsonPath('data.form_options.business_entities.0.name', $businessEntity->name);
    }

    public function test_ita_integration_method_errors_do_not_expose_laravel_debug_details(): void
    {
        config(['app.debug' => true]);

        $response = $this->postJson('/api/integrations/whatsapp/helpdesk/master-data');

        $response
            ->assertStatus(405)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('result_status', 'validation_error')
            ->assertJsonPath('error.code', 'method_not_allowed')
            ->assertJsonMissingPath('exception')
            ->assertJsonMissingPath('file')
            ->assertJsonMissingPath('trace');
    }

    public function test_ita_create_ticket_returns_form_options_when_classification_missing(): void
    {
        $this->helpdeskUser();

        $this->postJson('/api/integrations/whatsapp/helpdesk/actions', [
            'request_id' => 'trace-validation-001',
            'idempotency_key' => 'ita:create:validation',
            'action' => 'helpdesk.create_ticket',
            'actor' => [
                'phone' => '6281234567890',
                'is_verified' => true,
            ],
            'ticket_data' => [
                'issue_summary' => 'Email error',
                'affected_system' => 'Email',
                'impact' => 'Tidak bisa kerja',
                'consent_to_create' => true,
            ],
        ])
            ->assertStatus(422)
            ->assertJsonPath('result_status', 'validation_error')
            ->assertJsonPath('error.code', 'HELPDESK_FORM_INCOMPLETE')
            ->assertJsonPath('data.missing_fields.0', 'business_entities_id')
            ->assertJsonPath('data.field_errors.0.reason', 'missing')
            ->assertJsonPath('message', 'Entitas bisnis belum dipilih.')
            ->assertJsonStructure([
                'data' => [
                    'form_options' => [
                        'units',
                        'problem_categories',
                        'priorities',
                        'business_entities',
                    ],
                    'field_errors',
                ],
            ]);
    }

    public function test_ita_create_ticket_reports_unknown_business_entity_with_valid_options(): void
    {
        $this->helpdeskUser();
        $category = $this->problemCategory();
        $priority = Priority::create(['id' => Priority::MEDIUM, 'name' => 'Medium']);
        BusinessEntity::create(['name' => 'Complete Selular']);

        $response = $this->postJson('/api/integrations/whatsapp/helpdesk/actions', [
            'request_id' => 'trace-validation-002',
            'idempotency_key' => 'ita:create:unknown-entity',
            'action' => 'helpdesk.create_ticket',
            'actor' => [
                'phone' => '6281234567890',
                'is_verified' => true,
            ],
            'ticket_data' => [
                'business_entity' => 'Toko Mars',
                'unit_id' => $category->unit_id,
                'problem_category_id' => $category->id,
                'priority_id' => $priority->id,
                'issue_summary' => 'Email error',
                'affected_system' => 'Email',
                'impact' => 'Tidak bisa kerja',
                'consent_to_create' => true,
            ],
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('result_status', 'validation_error')
            ->assertJsonPath('error.code', 'HELPDESK_VALUE_NOT_FOUND')
            ->assertJsonPath('data.field_errors.0.field', 'business_entities_id')
            ->assertJsonPath('data.field_errors.0.reason', 'not_found')
            ->assertJsonPath('data.field_errors.0.provided_value', 'Toko Mars');

        $message = $response->json('message');
        $this->assertStringContainsString('Toko Mars', $message);
        $this->assertStringContainsString('tidak ditemukan di Helpdesk', $message);
        $this->assertStringContainsString('1. Complete Selular', $message);
        $this->assertStringContainsString('Balas nomor atau tulis nama persis seperti di daftar.', $message);
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

    private function technicianUser(): User
    {
        $technician = User::create([
            'name' => 'Teknisi IT',
            'email' => 'teknisi@example.test',
            'password' => 'secret',
            'phone' => '6287777777777',
            'is_active' => true,
        ]);

        \Spatie\Permission\Models\Role::firstOrCreate([
            'name' => 'Admin Unit',
            'guard_name' => 'web',
        ]);

        $technician->assignRole('Admin Unit');

        return $technician;
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
            $table->string('email')->nullable()->unique();
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
