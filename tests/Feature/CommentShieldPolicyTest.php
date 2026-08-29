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
use Illuminate\Support\Facades\Notification;
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
        Notification::fake();

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

    public function test_only_destination_admin_can_moderate_another_users_comment(): void
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
        $admin->forceFill(['unit_id' => $unit->id])->save();
        $staff = User::create([
            'unit_id' => $unit->id,
            'name' => 'IT Staff',
            'email' => 'it.staff@example.com',
        ]);
        $staff->assignRole('Staff Unit');

        $otherUnit = Unit::create(['name' => 'Finance']);
        $unrelatedAdmin = User::create([
            'unit_id' => $otherUnit->id,
            'name' => 'Finance Admin',
            'email' => 'finance.admin@example.com',
        ]);
        $unrelatedAdmin->assignRole('Admin Unit');

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
        $this->assertFalse($staff->can('update', $comment));
        $this->assertFalse($staff->can('delete', $comment));
        $this->assertFalse($unrelatedAdmin->can('update', $comment));
        $this->assertFalse($unrelatedAdmin->can('delete', $comment));
    }

    public function test_cross_unit_sender_cannot_moderate_destination_comment(): void
    {
        $origin = Unit::create(['name' => 'Finance']);
        $destination = Unit::create(['name' => 'IT']);
        $sourceAdmin = User::create([
            'unit_id' => $origin->id,
            'name' => 'Finance Sender Admin',
            'email' => 'finance.sender@example.com',
        ]);
        $sourceAdmin->assignRole('Admin Unit');
        $destinationStaff = User::create([
            'unit_id' => $destination->id,
            'name' => 'IT Destination Staff',
            'email' => 'it.destination@example.com',
        ]);
        $destinationStaff->assignRole('Staff Unit');

        $priority = Priority::create(['name' => 'Medium']);
        $status = TicketStatus::create(['name' => 'Open']);
        $category = ProblemCategory::create([
            'name' => 'Email',
            'unit_id' => $destination->id,
        ]);
        $ticket = Ticket::create([
            'unit_id' => $destination->id,
            'owner_id' => $sourceAdmin->id,
            'problem_category_id' => $category->id,
            'priority_id' => $priority->id,
            'ticket_statuses_id' => $status->id,
            'title' => 'Email Finance',
            'description' => 'Tiket dikirim ke unit IT.',
        ]);
        $comment = Comment::create([
            'tiket_id' => $ticket->id,
            'user_id' => $destinationStaff->id,
            'comment' => 'Sedang kami proses.',
        ]);

        $this->assertTrue($sourceAdmin->can('view', $comment));
        $this->assertFalse($sourceAdmin->can('update', $comment));
        $this->assertFalse($sourceAdmin->can('delete', $comment));
        $this->assertTrue($destinationStaff->can('update', $comment));
    }

    public function test_soft_deleted_comment_cannot_be_viewed_or_mutated_by_owner_or_destination_admin(): void
    {
        $origin = Unit::create(['name' => 'Finance']);
        $destination = Unit::create(['name' => 'IT']);
        $owner = User::create([
            'unit_id' => $origin->id,
            'name' => 'Finance Ticket Owner',
            'email' => 'finance.owner@example.com',
        ]);
        $owner->assignRole('Admin Unit');
        $destinationAdmin = User::create([
            'unit_id' => $destination->id,
            'name' => 'IT Destination Admin',
            'email' => 'it.admin@example.com',
        ]);
        $destinationAdmin->assignRole('Admin Unit');

        $priority = Priority::create(['name' => 'High']);
        $status = TicketStatus::create(['name' => 'Open']);
        $category = ProblemCategory::create([
            'name' => 'Access',
            'unit_id' => $destination->id,
        ]);
        $ticket = Ticket::create([
            'unit_id' => $destination->id,
            'owner_id' => $owner->id,
            'problem_category_id' => $category->id,
            'priority_id' => $priority->id,
            'ticket_statuses_id' => $status->id,
            'title' => 'Akses aplikasi Finance',
            'description' => 'Tiket lintas unit untuk menguji komentar soft-delete.',
        ]);
        $comment = Comment::create([
            'tiket_id' => $ticket->id,
            'user_id' => $owner->id,
            'comment' => 'Komentar dari pemilik tiket.',
        ]);

        foreach ([$owner, $destinationAdmin] as $user) {
            $this->assertTrue($user->can('view', $comment));
            $this->assertTrue($user->can('update', $comment));
            $this->assertTrue($user->can('delete', $comment));
        }

        $comment->delete();
        $deletedComment = Comment::withTrashed()->findOrFail($comment->id);

        $this->assertTrue($deletedComment->trashed());

        foreach ([$owner, $destinationAdmin] as $user) {
            $this->assertFalse($user->can('view', $deletedComment));
            $this->assertFalse($user->can('update', $deletedComment));
            $this->assertFalse($user->can('delete', $deletedComment));
        }
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
