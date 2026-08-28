<?php

namespace Tests\Concerns;

use App\Models\ProblemCategory;
use App\Models\TicketStatus;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesHelpdeskIntegrationSchema
{
    protected function createTestSchema(): void
    {
        foreach ([
            'comments',
            'ticket_histories',
            'tickets',
            'helpdesk_identity_assertion_uses',
            'helpdesk_reporter_bindings',
            'mcp_ticket_creation_requests',
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
            $table->string('email_verified_via')->nullable();
            $table->string('password')->nullable();
            $table->string('remember_token')->nullable();
            $table->string('identity')->nullable();
            $table->string('phone', 20)->nullable()->unique();
            $table->string('phone_normalized', 20)->nullable()->index();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('helpdesk_reporter_bindings', function (Blueprint $table): void {
            $table->id();
            $table->char('client_key', 64);
            $table->string('channel', 64);
            $table->char('external_user_hash', 64);
            $table->unsignedBigInteger('user_id');
            $table->char('verified_phone_hash', 64);
            $table->string('verification_method', 32);
            $table->timestamp('verified_at');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['client_key', 'channel', 'external_user_hash']);
        });

        Schema::create('helpdesk_identity_assertion_uses', function (Blueprint $table): void {
            $table->id();
            $table->char('assertion_hash', 64)->unique();
            $table->char('intake_hash', 64);
            $table->timestamp('expires_at')->index();
            $table->timestamps();
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

        Schema::create('mcp_ticket_creation_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('client_key', 64);
            $table->char('key_hash', 64);
            $table->char('request_hash', 64);
            $table->string('status', 16);
            $table->json('response')->nullable();
            $table->timestamps();
            $table->unique(['client_key', 'key_hash']);
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

    protected function helpdeskUser(): User
    {
        return User::create([
            'name' => 'Apri',
            'email' => 'apri@example.test',
            'password' => 'secret',
            'phone' => '6281234567890',
            'is_active' => true,
        ]);
    }

    protected function problemCategory(): ProblemCategory
    {
        $unit = Unit::create(['name' => 'IT']);

        return ProblemCategory::create([
            'id' => 50,
            'unit_id' => $unit->id,
            'name' => 'Akses Akun',
        ]);
    }
}
