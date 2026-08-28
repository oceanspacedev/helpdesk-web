<?php

namespace Tests\Feature;

use App\Filament\Resources\TicketResource\RelationManagers\CommentsRelationManager;
use App\Models\Comment;
use App\Models\Priority;
use App\Models\ProblemCategory;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CommentShieldPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createTestSchema();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Create standard Comment permissions
        foreach ([
            'ViewAny:Comment',
            'View:Comment',
            'Create:Comment',
            'Update:Comment',
            'Delete:Comment',
            'Restore:Comment',
            'ForceDelete:Comment',
        ] as $permissionName) {
            Permission::firstOrCreate(['name' => $permissionName, 'guard_name' => 'web']);
        }

        $this->seed(RoleSeeder::class);
    }

    public function test_user_role_has_comment_permissions(): void
    {
        $userRole = Role::findByName('User', 'web');

        $this->assertTrue($userRole->hasPermissionTo('ViewAny:Comment'));
        $this->assertTrue($userRole->hasPermissionTo('View:Comment'));
        $this->assertTrue($userRole->hasPermissionTo('Create:Comment'));
        $this->assertTrue($userRole->hasPermissionTo('Update:Comment'));
    }

    public function test_comments_relation_manager_is_not_read_only_on_view_pages(): void
    {
        $relationManager = new CommentsRelationManager;
        $this->assertFalse($relationManager->isReadOnly());
    }

    public function test_pelapor_user_can_create_and_view_comment(): void
    {
        $user = User::create([
            'name' => 'Pelapor User',
            'email' => 'pelapor@example.com',
        ]);
        $user->assignRole('User');

        $this->assertTrue($user->can('create', Comment::class));
        $this->assertTrue($user->can('viewAny', Comment::class));

        $unit = Unit::create(['name' => 'IT Helpdesk']);
        $priority = Priority::create(['name' => 'Low']);
        $status = TicketStatus::create(['name' => 'Open']);
        $category = ProblemCategory::create(['name' => 'Hardware', 'unit_id' => $unit->id]);

        $ticket = Ticket::create([
            'unit_id' => $unit->id,
            'owner_id' => $user->id,
            'problem_category_id' => $category->id,
            'priority_id' => $priority->id,
            'ticket_statuses_id' => $status->id,
            'title' => 'Komputer Rusak',
            'description' => 'Test Ticket',
        ]);

        $comment = Comment::create([
            'tiket_id' => $ticket->id,
            'user_id' => $user->id,
            'comment' => 'Komentar dari pelapor',
        ]);

        $this->assertTrue($user->can('view', $comment));
        $this->assertTrue($user->can('update', $comment));
    }

    public function test_user_cannot_update_other_users_comment(): void
    {
        $author = User::create([
            'name' => 'Author User',
            'email' => 'author@example.com',
        ]);
        $author->assignRole('User');

        $otherUser = User::create([
            'name' => 'Other User',
            'email' => 'other@example.com',
        ]);
        $otherUser->assignRole('User');

        $unit = Unit::create(['name' => 'IT Helpdesk']);
        $priority = Priority::create(['name' => 'Low']);
        $status = TicketStatus::create(['name' => 'Open']);
        $category = ProblemCategory::create(['name' => 'Hardware', 'unit_id' => $unit->id]);

        $ticket = Ticket::create([
            'unit_id' => $unit->id,
            'owner_id' => $author->id,
            'problem_category_id' => $category->id,
            'priority_id' => $priority->id,
            'ticket_statuses_id' => $status->id,
            'title' => 'Komputer Rusak',
            'description' => 'Test Ticket',
        ]);

        $comment = Comment::create([
            'tiket_id' => $ticket->id,
            'user_id' => $author->id,
            'comment' => 'Komentar author',
        ]);

        $this->assertFalse($otherUser->can('update', $comment));
    }

    public function test_user_without_permission_cannot_create_comment(): void
    {
        $userWithoutRole = User::create([
            'name' => 'No Role User',
            'email' => 'norole@example.com',
        ]);

        $this->assertFalse($userWithoutRole->can('create', Comment::class));
    }

    public function test_admin_unit_can_update_any_comment(): void
    {
        $author = User::create([
            'name' => 'Author User',
            'email' => 'author2@example.com',
        ]);
        $author->assignRole('User');

        $admin = User::create([
            'name' => 'Admin Unit User',
            'email' => 'admin@example.com',
        ]);
        $admin->assignRole('Admin Unit');

        $unit = Unit::create(['name' => 'IT Helpdesk']);
        $priority = Priority::create(['name' => 'Low']);
        $status = TicketStatus::create(['name' => 'Open']);
        $category = ProblemCategory::create(['name' => 'Hardware', 'unit_id' => $unit->id]);

        $ticket = Ticket::create([
            'unit_id' => $unit->id,
            'owner_id' => $author->id,
            'problem_category_id' => $category->id,
            'priority_id' => $priority->id,
            'ticket_statuses_id' => $status->id,
            'title' => 'Komputer Rusak',
            'description' => 'Test Ticket',
        ]);

        $comment = Comment::create([
            'tiket_id' => $ticket->id,
            'user_id' => $author->id,
            'comment' => 'Komentar author',
        ]);

        $this->assertTrue($admin->can('update', $comment));
        $this->assertTrue($admin->can('delete', $comment));
    }

    private function createTestSchema(): void
    {
        foreach ([
            'ticket_histories',
            'comments',
            'tickets',
            'problem_categories',
            'units',
            'priorities',
            'ticket_statuses',
            'model_has_permissions',
            'role_has_permissions',
            'model_has_roles',
            'permissions',
            'roles',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

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

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name')->default('web');
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name')->default('web');
            $table->timestamps();
        });

        Schema::create('model_has_roles', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->primary(['role_id', 'model_id', 'model_type']);
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
            $table->unsignedBigInteger('user_id')->nullable();
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
    }
}
