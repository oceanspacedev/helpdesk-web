<?php

namespace Tests\Feature;

use App\Filament\Resources\BusinessEntityResource;
use App\Filament\Resources\BusinessEntityResource\Pages\ManageBusinessEntities;
use App\Filament\Resources\ProblemCategoryResource;
use App\Filament\Resources\ProblemCategoryResource\Pages\CreateProblemCategory;
use App\Filament\Resources\ProblemCategoryResource\Pages\EditProblemCategory;
use App\Filament\Resources\ProblemCategoryResource\Pages\ViewProblemCategory;
use App\Filament\Resources\ProblemCategoryResource\RelationManagers\TicketsRelationManager;
use App\Filament\Resources\TicketResource\Pages\EditTicket;
use App\Filament\Resources\TicketResource\Pages\ListTickets;
use App\Filament\Resources\TicketResource\Pages\ViewTicket;
use App\Filament\Resources\TicketResource\RelationManagers\CommentsRelationManager;
use App\Filament\Resources\TicketStatusResource;
use App\Filament\Resources\TicketStatusResource\Pages\ManageTicketStatuses;
use App\Filament\Resources\UnitResource;
use App\Filament\Resources\UnitResource\Pages\ManageUnits;
use App\Filament\Resources\UnitSlas\Pages\ManageUnitSlas;
use App\Filament\Resources\UnitSlas\UnitSlaResource;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\ViewUser;
use App\Filament\Resources\UserResource\RelationManagers\TicketsRelationManager as UserTicketsRelationManager;
use App\Models\BusinessEntity;
use App\Models\Comment;
use App\Models\Priority;
use App\Models\ProblemCategory;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\Unit;
use App\Models\UnitSla;
use App\Models\User;
use App\Notifications\CommentNotification;
use App\Notifications\NewTicketNotification;
use App\Notifications\TicketStatusChangedNotification;
use App\Notifications\TicketSubmittedNotification;
use App\Policies\TicketPolicy;
use App\Services\WhatsAppGateway;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Schemas\Schema as FilamentSchema;
use Filament\Support\Enums\Width;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Concerns\CreatesHelpdeskIntegrationSchema;
use Tests\Fakes\RecordingWhatsAppGateway;
use Tests\TestCase;

class TicketMailboxAccessTest extends TestCase
{
    use CreatesHelpdeskIntegrationSchema;

    private Priority $priority;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'user_entities',
            'role_has_permissions',
            'model_has_permissions',
            'permissions',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        $this->createTestSchema();
        $this->createMailboxAuthorizationSchema();
        $this->seedTicketPermissionsAndRoles();

        $this->priority = Priority::create(['name' => 'Medium']);
    }

    protected function tearDown(): void
    {
        Ticket::$isSeeding = false;

        parent::tearDown();
    }

    public function test_cross_unit_sender_sees_outgoing_ticket_but_cannot_process_it(): void
    {
        $origin = Unit::create(['name' => 'Finance']);
        $destination = Unit::create(['name' => 'IT']);
        $sender = $this->unitUser('Admin Unit', $origin, 'Finance Admin');
        $ticket = $this->ticket($sender, $destination, 'Email finance bermasalah');

        $this->assertEqualsCanonicalizing([$origin->id], $sender->assignedUnitIds());
        $this->assertFalse($sender->hasGlobalTicketAccess());
        $this->assertTrue($sender->canProcessTickets());

        $this->assertTrue(
            Ticket::query()->where('owner_id', $sender->id)->whereKey($ticket)->exists(),
            'The sender must retain the ticket in their outgoing mailbox.',
        );
        $this->assertTrue(Ticket::query()->visibleTo($sender)->whereKey($ticket)->exists());
        $this->assertFalse(Ticket::query()->incomingFor($sender)->whereKey($ticket)->exists());
        $this->assertTrue(Gate::forUser($sender)->allows('view', $ticket));
        $this->assertTrue(Gate::forUser($sender)->allows('update', $ticket));
        $this->assertFalse(
            Gate::forUser($sender)->allows('process', $ticket),
            'An Admin Unit sender must not process a ticket addressed to another unit.',
        );
    }

    public function test_destination_admin_and_staff_see_incoming_ticket_and_may_process_it(): void
    {
        $origin = Unit::create(['name' => 'Finance']);
        $destination = Unit::create(['name' => 'IT']);
        $sender = $this->unitUser('Admin Unit', $origin, 'Finance Admin');
        $destinationAdmin = $this->unitUser('Admin Unit', $destination, 'IT Admin');
        $destinationStaff = $this->unitUser('Staff Unit', $destination, 'IT Staff');
        $ticket = $this->ticket($sender, $destination, 'Email finance bermasalah');

        foreach ([$destinationAdmin, $destinationStaff] as $processor) {
            $this->assertEqualsCanonicalizing([$destination->id], $processor->assignedUnitIds());
            $this->assertFalse($processor->hasGlobalTicketAccess());
            $this->assertTrue($processor->canProcessTickets());
            $this->assertTrue(Ticket::query()->incomingFor($processor)->whereKey($ticket)->exists());
            $this->assertTrue(Ticket::query()->visibleTo($processor)->whereKey($ticket)->exists());
            $this->assertTrue(Gate::forUser($processor)->allows('view', $ticket));
            $this->assertFalse(Gate::forUser($processor)->allows('update', $ticket));
            $this->assertTrue(Gate::forUser($processor)->allows('process', $ticket));
        }
    }

    public function test_any_active_division_member_processes_incoming_tickets(): void
    {
        $origin = Unit::create(['name' => 'Finance']);
        $destination = Unit::create(['name' => 'IT']);
        $sender = $this->unitUser('Admin Unit', $origin, 'Finance Admin');
        $member = $this->unitUser('User', $destination, 'IT Member');
        $inactive = $this->unitUser('User', $destination, 'IT Member Nonaktif');
        $inactive->update(['is_active' => false]);
        $ticket = $this->ticket($sender, $destination, 'Email finance bermasalah');

        // Anggota divisi adalah penindak walau tanpa role Staff/Admin Unit.
        $this->assertFalse($member->can('Update:Ticket'));
        $this->assertTrue($member->canProcessTickets());
        $this->assertTrue(Ticket::query()->incomingFor($member)->whereKey($ticket)->exists());
        $this->assertTrue(Gate::forUser($member)->allows('view', $ticket));
        $this->assertFalse(Gate::forUser($member)->allows('update', $ticket));
        $this->assertTrue(Gate::forUser($member)->allows('process', $ticket));
        $this->assertFalse(Gate::forUser($inactive->fresh())->allows('process', $ticket));
        $this->assertFalse(Gate::forUser($sender)->allows('process', $ticket));
    }

    public function test_new_ticket_notifies_every_active_member_of_the_destination_division(): void
    {
        Notification::fake();
        $origin = Unit::create(['name' => 'Finance']);
        $destination = Unit::create(['name' => 'Human Capital']);
        $category = ProblemCategory::create(['unit_id' => $destination->id, 'name' => 'Payroll']);
        $reporter = $this->unitUser('User', $origin, 'Finance Reporter');
        $staff = $this->unitUser('Staff Unit', $destination, 'HC Staff');
        $member = $this->unitUser('User', $destination, 'HC Member');
        $inactive = $this->unitUser('User', $destination, 'HC Member Nonaktif');
        $inactive->update(['is_active' => false]);
        $outsider = $this->unitUser('User', $origin, 'Finance Colleague');

        $this->actingAs($reporter);
        Ticket::create([
            'priority_id' => $this->priority->id,
            'unit_id' => $destination->id,
            'owner_id' => $reporter->id,
            'problem_category_id' => $category->id,
            'title' => 'Slip gaji belum keluar',
            'description' => 'Slip gaji bulan ini belum bisa diunduh.',
            'ticket_statuses_id' => TicketStatus::OPEN,
        ]);

        Notification::assertSentTo([$staff, $member], NewTicketNotification::class);
        Notification::assertNotSentTo([$inactive, $outsider, $reporter], NewTicketNotification::class);
        Notification::assertSentTo($reporter, TicketSubmittedNotification::class);
    }

    public function test_in_progress_ticket_can_only_be_processed_by_its_destination_responsible_user(): void
    {
        $origin = Unit::create(['name' => 'Finance']);
        $destination = Unit::create(['name' => 'IT']);
        $sender = $this->unitUser('Admin Unit', $origin, 'Finance Admin');
        $responsible = $this->unitUser('Staff Unit', $destination, 'IT Responsible Staff');
        $otherAdmin = $this->unitUser('Admin Unit', $destination, 'Other IT Admin');
        $otherStaff = $this->unitUser('Staff Unit', $destination, 'Other IT Staff');
        $ticket = $this->ticket(
            $sender,
            $destination,
            'Email finance sedang diproses',
            status: TicketStatus::IN_PROGRESS,
            responsible: $responsible,
        );

        foreach ([$responsible, $otherAdmin, $otherStaff] as $processor) {
            $this->assertTrue(Ticket::query()->incomingFor($processor)->whereKey($ticket)->exists());
            $this->assertTrue(Gate::forUser($processor)->allows('view', $ticket));
        }

        $this->assertFalse(Gate::forUser($responsible)->allows('update', $ticket));
        $this->assertTrue(Gate::forUser($responsible)->allows('process', $ticket));
        $this->assertFalse(Gate::forUser($otherAdmin)->allows('process', $ticket));
        $this->assertFalse(Gate::forUser($otherStaff)->allows('process', $ticket));
    }

    public function test_cancelled_and_closed_tickets_cannot_be_updated_by_destination_processors(): void
    {
        $origin = Unit::create(['name' => 'Finance']);
        $destination = Unit::create(['name' => 'IT']);
        $sender = $this->unitUser('Admin Unit', $origin, 'Finance Admin');
        $destinationAdmin = $this->unitUser('Admin Unit', $destination, 'IT Admin');
        $destinationStaff = $this->unitUser('Staff Unit', $destination, 'IT Staff');
        $cancelled = $this->ticket(
            $sender,
            $destination,
            'Email finance dibatalkan',
            status: TicketStatus::CANCEL,
            responsible: $destinationAdmin,
        );
        $closed = $this->ticket(
            $sender,
            $destination,
            'Email finance selesai',
            status: TicketStatus::CLOSED,
            responsible: $destinationStaff,
        );

        foreach ([$destinationAdmin, $destinationStaff] as $processor) {
            foreach ([$cancelled, $closed] as $terminalTicket) {
                $this->assertTrue(Gate::forUser($processor)->allows('view', $terminalTicket));
                $this->assertFalse(Gate::forUser($processor)->allows('update', $terminalTicket));
                $this->assertFalse(Gate::forUser($processor)->allows('process', $terminalTicket));
            }
        }

        $global = $this->user('Super Admin', 'Terminal Global Admin');

        $policy = app(TicketPolicy::class);

        $this->assertFalse($policy->process($global, $cancelled));
        $this->assertFalse($policy->process($global, $closed));
    }

    public function test_unrelated_unit_cannot_see_or_update_cross_unit_ticket(): void
    {
        $origin = Unit::create(['name' => 'Finance']);
        $destination = Unit::create(['name' => 'IT']);
        $unrelated = Unit::create(['name' => 'HR']);
        $sender = $this->unitUser('Admin Unit', $origin, 'Finance Admin');
        $unrelatedAdmin = $this->unitUser('Admin Unit', $unrelated, 'HR Admin');
        $unrelatedStaff = $this->unitUser('Staff Unit', $unrelated, 'HR Staff');
        $ticket = $this->ticket($sender, $destination, 'Email finance bermasalah');

        foreach ([$unrelatedAdmin, $unrelatedStaff] as $user) {
            $this->assertTrue($user->can('Update:Ticket'));
            $this->assertFalse(Ticket::query()->incomingFor($user)->whereKey($ticket)->exists());
            $this->assertFalse(Ticket::query()->visibleTo($user)->whereKey($ticket)->exists());
            $this->assertFalse(Gate::forUser($user)->allows('view', $ticket));
            $this->assertFalse(Gate::forUser($user)->allows('update', $ticket));
            $this->assertFalse(Gate::forUser($user)->allows('process', $ticket));
        }
    }

    public function test_super_admin_has_global_ticket_visibility_and_update_access(): void
    {
        $origin = Unit::create(['name' => 'Finance']);
        $destination = Unit::create(['name' => 'IT']);
        $sender = $this->unitUser('Admin Unit', $origin, 'Finance Admin');
        $superAdmin = $this->user('Super Admin', 'Super Admin');
        $ticket = $this->ticket($sender, $destination, 'Email finance bermasalah');

        $this->assertTrue($superAdmin->hasGlobalTicketAccess());
        $this->assertTrue($superAdmin->canProcessTickets());
        $this->assertTrue(Ticket::query()->visibleTo($superAdmin)->whereKey($ticket)->exists());
        $this->assertTrue(Gate::forUser($superAdmin)->allows('view', $ticket));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('update', $ticket));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('process', $ticket));
    }

    public function test_staff_unit_receives_ticket_create_permission_from_role_seeder(): void
    {
        $unit = Unit::create(['name' => 'IT']);
        $staff = $this->unitUser('Staff Unit', $unit, 'IT Staff');

        $this->assertTrue($staff->can('Create:Ticket'));
        $this->assertTrue(Gate::forUser($staff)->allows('create', Ticket::class));
    }

    public function test_permission_migration_grants_create_ticket_to_existing_staff_role(): void
    {
        $unit = Unit::create(['name' => 'IT']);
        $staff = $this->unitUser('Staff Unit', $unit, 'Existing IT Staff');
        $staff->roles()->firstOrFail()->revokePermissionTo('Create:Ticket');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertFalse($staff->fresh()->can('Create:Ticket'));

        $migration = require database_path('migrations/2026_08_29_000002_grant_staff_unit_create_ticket_permission.php');
        $migration->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue($staff->fresh()->can('Create:Ticket'));
    }

    public function test_legacy_unit_backfill_never_overrides_existing_pivot_membership(): void
    {
        $legacyUnit = Unit::create(['name' => 'Legacy Unit']);
        $currentUnit = Unit::create(['name' => 'Current Unit']);

        $legacyOnly = User::create([
            'unit_id' => $legacyUnit->id,
            'name' => 'Legacy Only',
            'email' => 'legacy.only@example.test',
            'is_active' => true,
        ]);
        $alreadyMigrated = User::create([
            'unit_id' => $legacyUnit->id,
            'name' => 'Already Migrated',
            'email' => 'already.migrated@example.test',
            'is_active' => true,
        ]);
        $alreadyMigrated->units()->attach($currentUnit->id);

        $migration = require database_path('migrations/2026_08_29_000003_backfill_user_unit_memberships.php');
        $migration->up();

        $this->assertSame([$legacyUnit->id], $legacyOnly->fresh()->assignedUnitIds());
        $this->assertSame([$currentUnit->id], $alreadyMigrated->fresh()->assignedUnitIds());

        $alreadyMigrated->units()->detach();

        $this->assertSame([], $alreadyMigrated->fresh()->assignedUnitIds());
    }

    public function test_problem_category_management_uses_authoritative_unit_memberships(): void
    {
        $legacyUnit = Unit::create(['name' => 'Legacy Finance']);
        $currentUnit = Unit::create(['name' => 'Current IT']);
        $admin = $this->user('Admin Unit', 'Moved Category Admin', $legacyUnit);
        $admin->units()->attach($currentUnit->id);
        $legacyStaff = $this->unitUser('Staff Unit', $legacyUnit, 'Legacy Unit Staff');
        $currentStaff = $this->unitUser('Staff Unit', $currentUnit, 'Current Unit Staff');
        $legacyCategory = ProblemCategory::create([
            'unit_id' => $legacyUnit->id,
            'name' => 'Legacy Category',
        ]);
        $currentCategory = ProblemCategory::create([
            'unit_id' => $currentUnit->id,
            'name' => 'Current Category',
        ]);

        $this->actingAs($admin->fresh());

        $this->assertSame(
            [$currentCategory->id],
            ProblemCategoryResource::getEloquentQuery()->pluck('id')->all(),
        );
        $this->assertSame(
            [$currentUnit->id => $currentUnit->name],
            ProblemCategoryResource::manageableUnitOptions(),
        );
        $this->assertFalse(Gate::forUser($admin)->allows('view', $legacyCategory));
        $this->assertFalse(Gate::forUser($admin)->allows('update', $legacyCategory));
        $this->assertFalse(Gate::forUser($admin)->allows('delete', $legacyCategory));
        $this->assertTrue(Gate::forUser($admin)->allows('view', $currentCategory));
        $this->assertTrue(Gate::forUser($admin)->allows('update', $currentCategory));
        $this->assertTrue(Gate::forUser($admin)->allows('delete', $currentCategory));
        $this->assertEqualsCanonicalizing(
            [$admin->id, $currentStaff->id],
            UserResource::getEloquentQuery()->pluck('id')->all(),
        );
        $this->assertNotContains($legacyStaff->id, UserResource::getEloquentQuery()->pluck('id')->all());
    }

    public function test_unit_sla_master_data_follows_shield_view_any(): void
    {
        $unit = Unit::create(['name' => 'IT']);
        $reporter = $this->user('User', 'Sla Reporter', $unit);
        $staff = $this->unitUser('Staff Unit', $unit, 'Sla Staff');
        $admin = $this->unitUser('Admin Unit', $unit, 'Sla Admin');

        $this->actingAs($reporter);
        $this->assertFalse(UnitSlaResource::canViewAny());

        $this->actingAs($staff);
        $this->assertFalse(UnitSlaResource::canViewAny());

        $this->actingAs($admin);
        $this->assertTrue(UnitSlaResource::canViewAny());
    }

    public function test_problem_category_ticket_relation_never_bypasses_mailbox_visibility(): void
    {
        $unit = Unit::create(['name' => 'IT']);
        $otherOrigin = Unit::create(['name' => 'Finance']);
        $reporterOrigin = Unit::create(['name' => 'Gudang']);
        $status = TicketStatus::create(['name' => 'Open']);
        // Pelapor dari divisi lain: anggota IT sendiri adalah penindak tiket IT.
        $reporter = $this->unitUser('User', $reporterOrigin, 'Assigned Reporter');
        $otherSender = $this->unitUser('Admin Unit', $otherOrigin, 'Other Finance Sender');
        $unrelatedStaff = $this->unitUser('Staff Unit', $otherOrigin, 'Unrelated Finance Staff');
        $category = ProblemCategory::create([
            'unit_id' => $unit->id,
            'name' => 'Shared IT Category',
        ]);
        $ownedTicket = $this->ticket(
            $reporter,
            $unit,
            'Reporter own category ticket',
            status: $status->id,
            category: $category,
        );
        $otherTicket = $this->ticket(
            $otherSender,
            $unit,
            'Another reporter category ticket',
            status: $status->id,
            category: $category,
        );
        $financeCategory = ProblemCategory::create([
            'unit_id' => $otherOrigin->id,
            'name' => 'Finance Category',
        ]);
        $reporterToFinance = $this->ticket(
            $reporter,
            $otherOrigin,
            'Reporter ticket to Finance',
            status: $status->id,
            category: $financeCategory,
        );

        $this->actingAs($unrelatedStaff);

        $this->assertFalse(Gate::forUser($unrelatedStaff)->allows('view', $category));
        $this->assertFalse(ProblemCategoryResource::getEloquentQuery()->whereKey($category)->exists());

        // Pelapor dari divisi lain tidak bisa membuka kategori IT. Anggota IT
        // adalah penindak sehingga melihat semua tiket di kategori itu.
        $this->assertFalse(Gate::forUser($reporter)->allows('view', $category));

        $itMember = $this->unitUser('User', $unit, 'IT Member');
        $this->actingAs($itMember);

        Livewire::test(TicketsRelationManager::class, [
            'ownerRecord' => $category,
            'pageClass' => ViewProblemCategory::class,
        ])
            ->assertCanSeeTableRecords([$ownedTicket, $otherTicket]);

        $this->actingAs($reporter);

        $this->assertSame(
            2,
            (int) TicketStatusResource::getEloquentQuery()->findOrFail($status->id)->tickets_count,
        );

        $this->assertSame(2, TicketStatusResource::visibleTicketsCount($status));

        $this->actingAs($otherSender);

        Livewire::test(UserTicketsRelationManager::class, [
            'ownerRecord' => $reporter,
            'pageClass' => ViewUser::class,
        ])
            ->assertCanSeeTableRecords([$reporterToFinance])
            ->assertCanNotSeeTableRecords([$ownedTicket]);
    }

    public function test_user_ticket_relation_is_registered_and_honors_mailbox_visibility(): void
    {
        $finance = Unit::create(['name' => 'Finance']);
        $it = Unit::create(['name' => 'IT']);
        $hr = Unit::create(['name' => 'HR']);
        $financeSender = $this->unitUser('Admin Unit', $finance, 'Finance Sender');
        $hrSender = $this->unitUser('Admin Unit', $hr, 'HR Sender');
        $this->ticket($financeSender, $it, 'Finance to IT');
        $hrTicket = $this->ticket($hrSender, $it, 'HR to IT');

        $this->assertContains(UserTicketsRelationManager::class, UserResource::getRelations());

        $this->actingAs($financeSender);

        $this->assertTrue(UserTicketsRelationManager::canViewForRecord($financeSender, ViewUser::class));

        Livewire::test(UserTicketsRelationManager::class, [
            'ownerRecord' => $hrSender,
            'pageClass' => ViewUser::class,
        ])
            ->assertCanNotSeeTableRecords([$hrTicket]);

        $userWithoutTicketPermission = User::create([
            'name' => 'No Ticket Permission',
            'email' => 'no-ticket-permission@example.test',
            'is_active' => true,
        ]);
        $this->actingAs($userWithoutTicketPermission);

        $this->assertFalse(UserTicketsRelationManager::canViewForRecord($financeSender, ViewUser::class));
    }

    public function test_units_are_managed_from_a_single_page_with_slide_over_modals(): void
    {
        $it = Unit::create(['name' => 'IT']);
        $busdev = Unit::create(['name' => 'BUSDEV']);
        $pic = $this->unitUser('Staff Unit', $it, 'IT Network PIC');
        $this->unitUser('User', $it, 'IT Member');
        $secondPic = $this->unitUser('Staff Unit', $it, 'IT Network Backup');
        ProblemCategory::create(['unit_id' => $it->id, 'name' => 'Jaringan'])
            ->defaultResponsibles()->attach([$pic->id, $secondPic->id]);
        ProblemCategory::create(['unit_id' => $it->id, 'name' => 'Email']);
        $superAdmin = $this->user('Super Admin', 'Unit Manager');

        $this->assertSame(['index'], array_keys(UnitResource::getPages()));
        $this->assertSame([], UnitResource::getRelations());
        $this->assertDirectoryDoesNotExist(app_path('Filament/Resources/UnitResource/RelationManagers'));

        $this->actingAs($superAdmin);

        $page = Livewire::test(ManageUnits::class)
            ->assertCanSeeTableRecords([$it, $busdev])
            ->assertTableColumnStateSet('problem_categories_count', 2, $it)
            ->assertTableColumnStateSet('users_count', 3, $it)
            ->assertTableColumnStateSet('tickets_count', 0, $it);

        $this->assertActionsOpenAsMediumSlideOvers($page, [
            'create' => TestAction::make('create'),
            'view' => TestAction::make('view')->table($it),
            'edit' => TestAction::make('edit')->table($it),
        ]);

        // Modal v4 dirender sebagai partial, jadi isi infolist dievaluasi langsung.
        $infolist = UnitResource::infolist(FilamentSchema::make($page->instance())->record($it->fresh()));
        $states = collect($infolist->getComponents())
            ->mapWithKeys(fn ($component) => [$component->getName() => $component->getState()]);
        $this->assertSame('IT', $states['name']);
        $this->assertSame(['Email', 'Jaringan · PIC: IT Network Backup, IT Network PIC'], $states['problem_categories']);
        $this->assertSame(['IT Member', 'IT Network Backup', 'IT Network PIC'], $states['members']);
        $this->assertSame(0, $states['tickets_total']);

        $page
            ->callAction(TestAction::make('create'), data: ['name' => 'Legal'])
            ->assertHasNoActionErrors()
            ->callAction(TestAction::make('edit')->table($it), data: ['name' => 'IT & Infrastruktur'])
            ->assertHasNoActionErrors();

        $this->assertTrue(Unit::query()->where('name', 'Legal')->exists());
        $this->assertSame('IT & Infrastruktur', $it->fresh()->name);
    }

    public function test_legacy_staf_unit_role_is_merged_without_losing_memberships_or_permissions(): void
    {
        $legacyRole = Role::create(['name' => 'Staf Unit', 'guard_name' => 'web']);
        $legacyRole->givePermissionTo('Delete:Ticket');
        $legacyStaff = User::create([
            'name' => 'Legacy Staf User',
            'email' => 'legacy.staf@example.test',
            'is_active' => true,
        ]);
        $legacyStaff->assignRole($legacyRole);

        $migration = require database_path('migrations/2026_08_29_000004_normalize_staff_unit_role_name.php');
        $migration->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $legacyStaff = $legacyStaff->fresh();

        $this->assertTrue($legacyStaff->hasRole('Staff Unit'));
        $this->assertTrue($legacyStaff->can('Create:Ticket'));
        $this->assertTrue($legacyStaff->can('Delete:Ticket'));
        $this->assertFalse(Role::query()->where('name', 'Staf Unit')->exists());
    }

    public function test_filament_mailbox_tabs_render_the_correct_records(): void
    {
        $origin = Unit::create(['name' => 'Finance']);
        $destination = Unit::create(['name' => 'IT']);
        $destinationAdmin = $this->unitUser('Admin Unit', $destination, 'IT Mailbox Admin');
        $originAdmin = $this->unitUser('Admin Unit', $origin, 'Finance Mailbox Admin');
        $incoming = $this->ticket($originAdmin, $destination, 'Incoming mailbox ticket');
        $outgoing = $this->ticket($destinationAdmin, $origin, 'Outgoing mailbox ticket');

        $this->actingAs($destinationAdmin);

        Livewire::test(ListTickets::class)
            ->assertSee('Tiket Masuk')
            ->assertSee('Tiket Keluar')
            ->assertCanSeeTableRecords([$incoming])
            ->assertCanNotSeeTableRecords([$outgoing])
            ->set('activeTab', 'keluar')
            ->assertCanSeeTableRecords([$outgoing])
            ->assertCanNotSeeTableRecords([$incoming]);
    }

    public function test_ticket_export_downloads_xlsx_scoped_to_the_active_mailbox_tab(): void
    {
        $finance = Unit::create(['name' => 'Finance']);
        $it = Unit::create(['name' => 'IT']);
        $hr = Unit::create(['name' => 'HR']);
        $financeSender = $this->unitUser('Admin Unit', $finance, 'Finance Export Sender');
        $hrAdmin = $this->unitUser('Admin Unit', $hr, 'HR Export Admin');
        $globalAdmin = $this->user('Super Admin', 'Global Export Admin');
        $financeTicket = $this->ticket($financeSender, $it, 'Finance to IT export secret');
        $month = (int) $financeTicket->created_at->format('n');
        $year = (int) $financeTicket->created_at->format('Y');
        $filename = 'export_ticket_'.$year.'_'.str_pad((string) $month, 2, '0', STR_PAD_LEFT).'.xlsx';

        $this->actingAs($hrAdmin);

        $hrExport = Livewire::test(ListTickets::class)
            ->assertCanNotSeeTableRecords([$financeTicket])
            ->mountAction('export')
            ->assertMountedActionModalSee([
                'Export Data Tiket per Bulan',
                'File XLSX mengikuti scope, pencarian, dan filter pada tab tiket yang sedang aktif.',
                'Unduh XLSX',
            ])
            ->fillForm([
                'month' => $month,
                'year' => $year,
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertFileDownloaded($filename);

        $this->assertNotContains(
            $financeTicket->title,
            $this->downloadedSpreadsheetValues($hrExport),
            'An unrelated HR user must not receive a Finance-to-IT ticket through export.',
        );

        $this->actingAs($financeSender);

        $outgoingExport = Livewire::test(ListTickets::class)
            ->assertCanNotSeeTableRecords([$financeTicket])
            ->set('activeTab', 'keluar')
            ->assertCanSeeTableRecords([$financeTicket])
            ->callAction('export', [
                'month' => $month,
                'year' => $year,
            ])
            ->assertHasNoActionErrors()
            ->assertFileDownloaded($filename);

        $this->assertContains(
            $financeTicket->title,
            $this->downloadedSpreadsheetValues($outgoingExport),
            'The export must switch with the active Tiket Keluar tab.',
        );

        $this->actingAs($globalAdmin);

        $globalExport = Livewire::test(ListTickets::class)
            ->assertCanSeeTableRecords([$financeTicket])
            ->callAction('export', [
                'month' => $month,
                'year' => $year,
            ])
            ->assertHasNoActionErrors()
            ->assertFileDownloaded($filename);

        $this->assertContains(
            $financeTicket->title,
            $this->downloadedSpreadsheetValues($globalExport),
            'A global administrator export must follow the global Semua Tiket scope.',
        );
    }

    public function test_destination_processor_can_claim_ticket_from_view_action(): void
    {
        $origin = Unit::create(['name' => 'Finance']);
        $destination = Unit::create(['name' => 'IT']);
        $sender = $this->unitUser('Admin Unit', $origin, 'Finance Action Admin');
        $destinationStaff = $this->unitUser('Staff Unit', $destination, 'IT Action Staff');
        $ticket = $this->ticket($sender, $destination, 'Ticket claimed from incoming mailbox');

        $this->actingAs($destinationStaff);

        Livewire::test(ViewTicket::class, ['record' => $ticket->getRouteKey()])
            ->assertActionDoesNotExist('edit')
            ->assertActionVisible('proses')
            ->callAction('proses')
            ->assertHasNoActionErrors();

        $ticket->refresh();

        $this->assertSame(TicketStatus::IN_PROGRESS, (int) $ticket->ticket_statuses_id);
        $this->assertSame($destinationStaff->id, (int) $ticket->responsible_id);
    }

    public function test_odoo_and_printer_are_not_assigned_by_legacy_category_ids(): void
    {
        Notification::fake();

        $it = Unit::create(['name' => 'IT']);
        $busdev = Unit::create(['name' => 'BUSDEV']);
        $odoo = ProblemCategory::create(['unit_id' => $it->id, 'name' => 'Odoo Program']);
        $printer = ProblemCategory::create(['unit_id' => $it->id, 'name' => 'Laptop, Komputer, Printer']);
        $sop = ProblemCategory::create(['unit_id' => $busdev->id, 'name' => 'Standard Operating Procedure (SOP)']);
        $sender = $this->unitUser('Admin Unit', $it, 'IT Reporter');
        $odLead = $this->unitUser('Staff Unit', $busdev, 'Organization Development');

        while ((int) User::query()->max('id') < 10) {
            $this->user('Staff Unit', 'Pad '.User::query()->count());
        }
        $decoy = User::query()->find(10);
        $this->assertNotNull($decoy);
        $decoy->assignRole('Staff Unit');
        $decoy->units()->syncWithoutDetaching([$it->id]);

        $this->actingAs($sender);

        $odooTicket = Ticket::create([
            'priority_id' => $this->priority->id,
            'unit_id' => $it->id,
            'owner_id' => $sender->id,
            'problem_category_id' => $odoo->id,
            'title' => 'Odoo tidak bisa login',
            'description' => 'Odoo tidak bisa login',
            'ticket_statuses_id' => TicketStatus::OPEN,
        ]);
        $printerTicket = Ticket::create([
            'priority_id' => $this->priority->id,
            'unit_id' => $it->id,
            'owner_id' => $sender->id,
            'problem_category_id' => $printer->id,
            'title' => 'Printer kasir error',
            'description' => 'Printer kasir error',
            'ticket_statuses_id' => TicketStatus::OPEN,
        ]);
        $sopTicket = Ticket::create([
            'priority_id' => $this->priority->id,
            'unit_id' => $busdev->id,
            'owner_id' => $sender->id,
            'problem_category_id' => $sop->id,
            'title' => 'Minta update SOP',
            'description' => 'Minta update SOP',
            'ticket_statuses_id' => TicketStatus::OPEN,
        ]);

        $this->assertSame(1, (int) $odoo->id);
        $this->assertNull($odooTicket->fresh()->responsible_id);
        $this->assertNull($printerTicket->fresh()->responsible_id);
        $this->assertSame($odLead->id, (int) $sopTicket->fresh()->responsible_id);
        $this->assertNotSame($decoy->id, (int) ($odooTicket->fresh()->responsible_id ?? 0));
    }

    public function test_owner_cannot_change_destination_unit_on_edit(): void
    {
        $origin = Unit::create(['name' => 'Finance']);
        $destination = Unit::create(['name' => 'IT']);
        $sender = $this->unitUser('Admin Unit', $origin, 'Finance Edit Owner');
        $ticket = $this->ticket($sender, $destination, 'Owner cannot reroute unit');

        $this->actingAs($sender);

        Livewire::test(EditTicket::class, ['record' => $ticket->getRouteKey()])
            ->assertFormFieldIsDisabled('unit_id');
    }

    public function test_super_admin_can_change_destination_unit_on_edit(): void
    {
        $origin = Unit::create(['name' => 'Finance']);
        $destination = Unit::create(['name' => 'IT']);
        $sender = $this->unitUser('Admin Unit', $origin, 'Finance Admin For Edit');
        $superAdmin = $this->user('Super Admin', 'Super Admin Unit Edit');
        $ticket = $this->ticket($sender, $destination, 'Global admin can reroute unit');

        $this->actingAs($superAdmin);

        Livewire::test(EditTicket::class, ['record' => $ticket->getRouteKey()])
            ->assertFormFieldIsEnabled('unit_id');
    }

    public function test_destination_processor_can_move_ticket_to_another_unit(): void
    {
        Notification::fake();

        $origin = Unit::create(['name' => 'Finance']);
        $it = Unit::create(['name' => 'IT']);
        $busdev = Unit::create(['name' => 'BUSDEV']);
        $itCategory = ProblemCategory::create(['unit_id' => $it->id, 'name' => 'Odoo Program']);
        $sop = ProblemCategory::create(['unit_id' => $busdev->id, 'name' => 'Standard Operating Procedure (SOP)']);
        $sender = $this->unitUser('Admin Unit', $origin, 'Finance Move Admin');
        $itStaff = $this->unitUser('Staff Unit', $it, 'IT Move Staff');
        $busdevStaff = $this->unitUser('Staff Unit', $busdev, 'BUSDEV Move Staff');
        $ticket = $this->ticket($sender, $it, 'Misrouted to IT', category: $itCategory);

        $this->actingAs($sender);
        Livewire::test(ViewTicket::class, ['record' => $ticket->getRouteKey()])
            ->assertActionDoesNotExist('pindah_unit');

        $this->actingAs($itStaff);
        Livewire::test(ViewTicket::class, ['record' => $ticket->getRouteKey()])
            ->assertActionVisible('pindah_unit')
            ->callAction('pindah_unit', [
                'unit_id' => $busdev->id,
                'problem_category_id' => $sop->id,
            ])
            ->assertHasNoActionErrors();

        $ticket->refresh();
        $this->assertSame($busdev->id, (int) $ticket->unit_id);
        $this->assertSame($sop->id, (int) $ticket->problem_category_id);
        $this->assertSame(TicketStatus::OPEN, (int) $ticket->ticket_statuses_id);
        $this->assertTrue(Gate::forUser($busdevStaff)->allows('process', $ticket));
        $this->assertFalse(Gate::forUser($itStaff)->allows('process', $ticket));
        Notification::assertSentTo($busdevStaff, NewTicketNotification::class);
    }

    public function test_destination_processor_can_take_over_from_an_ineligible_responsible(): void
    {
        $origin = Unit::create(['name' => 'Finance']);
        $destination = Unit::create(['name' => 'IT']);
        $sender = $this->unitUser('Admin Unit', $origin, 'Finance Takeover Admin');
        $formerStaff = $this->unitUser('Staff Unit', $destination, 'Former IT Staff');
        $replacement = $this->unitUser('Staff Unit', $destination, 'Replacement IT Staff');
        $ticket = $this->ticket(
            $sender,
            $destination,
            'Ticket needs takeover',
            status: TicketStatus::IN_PROGRESS,
            responsible: $formerStaff,
        );
        $formerStaff->forceFill(['is_active' => false])->save();

        $this->assertNull($ticket->fresh()->eligibleResponsible());
        $this->assertTrue(Gate::forUser($replacement)->allows('process', $ticket->fresh()));

        $this->actingAs($replacement);

        Livewire::test(ViewTicket::class, ['record' => $ticket->getRouteKey()])
            ->assertActionVisible('ambil_alih')
            ->assertActionDoesNotExist('selesai')
            ->callAction('ambil_alih')
            ->assertHasNoActionErrors();

        $ticket->refresh();

        $this->assertSame(TicketStatus::IN_PROGRESS, (int) $ticket->ticket_statuses_id);
        $this->assertSame($replacement->id, (int) $ticket->responsible_id);
    }

    public function test_comment_notifications_fall_back_to_active_destination_processors(): void
    {
        Notification::fake();

        $origin = Unit::create(['name' => 'Finance']);
        $destination = Unit::create(['name' => 'IT']);
        $sender = $this->unitUser('Admin Unit', $origin, 'Finance Comment Admin');
        $formerStaff = $this->unitUser('Staff Unit', $destination, 'Inactive Comment Staff');
        $replacement = $this->unitUser('Staff Unit', $destination, 'Active Comment Staff');
        $ticket = $this->ticket(
            $sender,
            $destination,
            'Ticket comment notification fallback',
            status: TicketStatus::IN_PROGRESS,
            responsible: $formerStaff,
        );
        $formerStaff->forceFill(['is_active' => false])->save();

        Comment::create([
            'tiket_id' => $ticket->id,
            'user_id' => $sender->id,
            'comment' => 'Mohon update progres.',
        ]);

        Notification::assertNotSentTo($formerStaff, CommentNotification::class);
        Notification::assertSentTo($replacement, CommentNotification::class);
    }

    public function test_closed_notification_is_dispatched_after_the_ticket_update_succeeds(): void
    {
        Notification::fake();

        $origin = Unit::create(['name' => 'Finance']);
        $destination = Unit::create(['name' => 'IT']);
        $sender = $this->unitUser('Admin Unit', $origin, 'Finance Closed Admin');
        $responsible = $this->unitUser('Staff Unit', $destination, 'IT Closing Staff');
        $ticket = $this->ticket(
            $sender,
            $destination,
            'Ticket to close safely',
            status: TicketStatus::IN_PROGRESS,
            responsible: $responsible,
        );

        $this->actingAs($responsible);
        $ticket->update(['ticket_statuses_id' => TicketStatus::CLOSED]);

        Notification::assertSentTo($sender, TicketStatusChangedNotification::class);
        $this->assertNotNull($ticket->fresh()->solved_at);
    }

    public function test_soft_deleted_ticket_is_read_only_for_workflow_and_comments(): void
    {
        Notification::fake();

        $origin = Unit::create(['name' => 'Finance']);
        $destination = Unit::create(['name' => 'IT']);
        $sender = $this->unitUser('Admin Unit', $origin, 'Deleted Ticket Sender');
        $destinationAdmin = $this->unitUser('Admin Unit', $destination, 'Deleted Ticket Admin');
        $ticket = $this->ticket($sender, $destination, 'Soft deleted ticket');
        $comment = Comment::create([
            'tiket_id' => $ticket->id,
            'user_id' => $sender->id,
            'comment' => 'Comment before deletion.',
        ]);

        $ticket->delete();
        $comment->unsetRelation('ticket');

        $this->assertTrue($ticket->trashed());
        $this->assertFalse(Gate::forUser($sender)->allows('update', $ticket));
        $this->assertFalse(Gate::forUser($destinationAdmin)->allows('process', $ticket));
        $this->assertFalse(Gate::forUser($sender)->allows('update', $comment));
        $this->assertFalse(Gate::forUser($destinationAdmin)->allows('delete', $comment));

        $this->actingAs($destinationAdmin);

        Livewire::test(ViewTicket::class, ['record' => $ticket->getRouteKey()])
            ->assertActionDoesNotExist('edit')
            ->assertActionDoesNotExist('proses');

        Livewire::test(CommentsRelationManager::class, [
            'ownerRecord' => $ticket,
            'pageClass' => ViewTicket::class,
        ])->assertActionDoesNotExist('create');
    }

    public function test_category_default_pics_receive_new_ticket_instead_of_the_whole_division(): void
    {
        Notification::fake();
        $origin = Unit::create(['name' => 'Finance']);
        $destination = Unit::create(['name' => 'IT']);
        $pic = $this->unitUser('Staff Unit', $destination, 'IT Network PIC');
        $backupPic = $this->unitUser('Staff Unit', $destination, 'IT Network Backup');
        $colleague = $this->unitUser('Staff Unit', $destination, 'IT Other Staff');
        $member = $this->unitUser('User', $destination, 'IT Member');
        $reporter = $this->unitUser('User', $origin, 'Finance Network Reporter');
        $category = ProblemCategory::create([
            'unit_id' => $destination->id,
            'name' => 'Jaringan & Internet',
        ]);
        $category->defaultResponsibles()->attach([$pic->id, $backupPic->id]);

        $this->actingAs($reporter);
        $ticket = Ticket::create([
            'priority_id' => $this->priority->id,
            'unit_id' => $destination->id,
            'owner_id' => $reporter->id,
            'problem_category_id' => $category->id,
            'title' => 'Internet kantor putus',
            'description' => 'Internet kantor putus sejak pagi.',
            'ticket_statuses_id' => TicketStatus::OPEN,
        ]);

        $this->assertSame($backupPic->id, (int) $ticket->fresh()->responsible_id, 'seri beban dipecah berdasarkan nama');
        Notification::assertSentTo([$pic, $backupPic], NewTicketNotification::class);
        Notification::assertNotSentTo([$colleague, $member, $reporter], NewTicketNotification::class);
        Notification::assertSentTo($reporter, TicketSubmittedNotification::class);
    }

    public function test_new_tickets_go_to_the_category_pic_with_the_fewest_active_tickets(): void
    {
        Notification::fake();
        $origin = Unit::create(['name' => 'Finance']);
        $destination = Unit::create(['name' => 'IT']);
        $andi = $this->unitUser('Staff Unit', $destination, 'Andi PIC');
        $budi = $this->unitUser('Staff Unit', $destination, 'Budi PIC');
        $colleague = $this->unitUser('Staff Unit', $destination, 'IT Other Staff');
        $reporter = $this->unitUser('User', $origin, 'Finance Rotation Reporter');
        $category = ProblemCategory::create(['unit_id' => $destination->id, 'name' => 'Jaringan']);
        $category->defaultResponsibles()->attach([$budi->id, $andi->id]);

        $this->actingAs($reporter);
        $create = fn (string $title): Ticket => Ticket::create([
            'priority_id' => $this->priority->id,
            'unit_id' => $destination->id,
            'owner_id' => $reporter->id,
            'problem_category_id' => $category->id,
            'title' => $title,
            'description' => $title,
            'ticket_statuses_id' => TicketStatus::OPEN,
        ]);

        $first = $create('Tiket 1');
        $second = $create('Tiket 2');
        $third = $create('Tiket 3');

        $this->assertSame($andi->id, (int) $first->fresh()->responsible_id, 'seri 0-0 dipecah berdasarkan nama');
        $this->assertSame($budi->id, (int) $second->fresh()->responsible_id, 'Andi sudah punya 1 tiket aktif');
        $this->assertSame($andi->id, (int) $third->fresh()->responsible_id, 'seri 1-1 kembali ke Andi');

        // Tiket yang ditutup atau dibatalkan tidak dihitung sebagai beban.
        $first->update(['ticket_statuses_id' => TicketStatus::CLOSED]);
        $third->update(['ticket_statuses_id' => TicketStatus::CANCEL]);
        $fourth = $create('Tiket 4');

        $this->assertSame($andi->id, (int) $fourth->fresh()->responsible_id, 'Andi 0 aktif, Budi 1 aktif');

        Notification::assertSentToTimes($andi, NewTicketNotification::class, 4);
        Notification::assertSentToTimes($budi, NewTicketNotification::class, 4);
        Notification::assertNotSentTo([$colleague, $reporter], NewTicketNotification::class);
        Notification::assertSentToTimes($reporter, TicketSubmittedNotification::class, 4);
    }

    public function test_inactive_or_relocated_category_pic_falls_back_to_the_division_queue(): void
    {
        Notification::fake();
        $origin = Unit::create(['name' => 'Finance']);
        $destination = Unit::create(['name' => 'IT']);
        $other = Unit::create(['name' => 'Human Capital']);
        $inactivePic = $this->unitUser('Staff Unit', $destination, 'IT Resigned PIC');
        $inactivePic->update(['is_active' => false]);
        $movedPic = $this->unitUser('Staff Unit', $other, 'Former IT PIC');
        $staff = $this->unitUser('Staff Unit', $destination, 'IT Queue Staff');
        $activePic = $this->unitUser('Staff Unit', $destination, 'IT Remaining PIC');
        $reporter = $this->unitUser('User', $origin, 'Finance Fallback Reporter');
        $inactiveCategory = ProblemCategory::create(['unit_id' => $destination->id, 'name' => 'Email']);
        $inactiveCategory->defaultResponsibles()->attach($inactivePic->id);
        $movedCategory = ProblemCategory::create(['unit_id' => $destination->id, 'name' => 'CCTV']);
        $movedCategory->defaultResponsibles()->attach($movedPic->id);
        $mixedCategory = ProblemCategory::create(['unit_id' => $destination->id, 'name' => 'Printer']);
        $mixedCategory->defaultResponsibles()->attach([$inactivePic->id, $movedPic->id, $activePic->id]);

        $this->actingAs($reporter);
        foreach ([$inactiveCategory, $movedCategory] as $category) {
            $ticket = Ticket::create([
                'priority_id' => $this->priority->id,
                'unit_id' => $destination->id,
                'owner_id' => $reporter->id,
                'problem_category_id' => $category->id,
                'title' => 'Tiket '.$category->name,
                'description' => 'Tiket '.$category->name,
                'ticket_statuses_id' => TicketStatus::OPEN,
            ]);

            $this->assertNull($ticket->fresh()->responsible_id, $category->name);
        }

        Notification::assertSentToTimes($staff, NewTicketNotification::class, 2);
        Notification::assertSentToTimes($activePic, NewTicketNotification::class, 2);

        // Bila masih ada satu PIC yang eligible, hanya dia yang dapat tiket dan notifikasinya.
        $mixedTicket = Ticket::create([
            'priority_id' => $this->priority->id,
            'unit_id' => $destination->id,
            'owner_id' => $reporter->id,
            'problem_category_id' => $mixedCategory->id,
            'title' => 'Printer macet',
            'description' => 'Printer macet',
            'ticket_statuses_id' => TicketStatus::OPEN,
        ]);

        $this->assertSame($activePic->id, (int) $mixedTicket->fresh()->responsible_id);
        Notification::assertSentToTimes($activePic, NewTicketNotification::class, 3);
        Notification::assertSentToTimes($staff, NewTicketNotification::class, 2);
        Notification::assertNotSentTo([$inactivePic, $movedPic], NewTicketNotification::class);
    }

    public function test_category_default_pic_overrides_legacy_busdev_mapping(): void
    {
        Notification::fake();
        $it = Unit::create(['name' => 'IT']);
        $busdev = Unit::create(['name' => 'BUSDEV']);
        $odLead = $this->unitUser('Staff Unit', $busdev, 'Organization Development');
        $sopPic = $this->unitUser('Staff Unit', $busdev, 'SOP Specialist');
        $sender = $this->unitUser('Admin Unit', $it, 'IT Reporter');
        $sop = ProblemCategory::create([
            'unit_id' => $busdev->id,
            'name' => 'Standard Operating Procedure (SOP)',
        ]);
        $sop->defaultResponsibles()->attach($sopPic->id);

        $this->actingAs($sender);
        $ticket = Ticket::create([
            'priority_id' => $this->priority->id,
            'unit_id' => $busdev->id,
            'owner_id' => $sender->id,
            'problem_category_id' => $sop->id,
            'title' => 'Minta update SOP',
            'description' => 'Minta update SOP',
            'ticket_statuses_id' => TicketStatus::OPEN,
        ]);

        $this->assertSame($sopPic->id, (int) $ticket->fresh()->responsible_id);
        Notification::assertSentTo($sopPic, NewTicketNotification::class);
        Notification::assertNotSentTo($odLead, NewTicketNotification::class);
    }

    public function test_default_pic_migrations_backfill_legacy_busdev_categories_into_the_pivot(): void
    {
        $it = Unit::create(['name' => 'IT']);
        $busdev = Unit::create(['name' => 'BUSDEV']);
        $odLead = $this->unitUser('Staff Unit', $busdev, 'Organization Development');
        $odStaff = $this->unitUser('Staff Unit', $busdev, 'Organization Development Staff');
        $sop = ProblemCategory::create(['unit_id' => $busdev->id, 'name' => 'Standard Operating Procedure (SOP)']);
        $jd = ProblemCategory::create(['unit_id' => $busdev->id, 'name' => 'Job Description (JD)']);
        $odoo = ProblemCategory::create(['unit_id' => $it->id, 'name' => 'Odoo Program']);
        $strayScript = ProblemCategory::create(['unit_id' => $it->id, 'name' => 'Script']);
        $manual = $this->unitUser('Staff Unit', $busdev, 'Manual SE PIC');
        $se = ProblemCategory::create(['unit_id' => $busdev->id, 'name' => 'Surat Edaran (SE)']);
        $alreadyPivoted = $this->unitUser('Staff Unit', $busdev, 'Already Pivoted PIC');
        $se->defaultResponsibles()->attach($alreadyPivoted->id);

        // Migrasi pertama membuat kolom tunggal dan mengisi pemetaan lama BUSDEV.
        $singleColumn = require database_path('migrations/2026_11_09_000001_add_default_responsible_to_problem_categories_table.php');
        $singleColumn->up();
        $this->assertTrue(Schema::hasColumn('problem_categories', 'default_responsible_id'));
        ProblemCategory::query()->whereKey($se->id)->update(['default_responsible_id' => $manual->id]);

        // Migrasi kedua memindahkan kolom itu ke tabel pivot lalu menghapusnya.
        $pivot = require database_path('migrations/2026_11_09_000002_create_problem_category_responsibles_table.php');
        $pivot->up();

        $this->assertFalse(Schema::hasColumn('problem_categories', 'default_responsible_id'));
        $this->assertSame([$odLead->id], $sop->fresh()->defaultResponsibles->modelKeys());
        $this->assertSame([$odStaff->id], $jd->fresh()->defaultResponsibles->modelKeys());
        $this->assertSame([], $odoo->fresh()->defaultResponsibles->modelKeys());
        $this->assertSame([], $strayScript->fresh()->defaultResponsibles->modelKeys(), 'OD lead is not an IT processor');
        $this->assertEqualsCanonicalizing(
            [$alreadyPivoted->id, $manual->id],
            $se->fresh()->defaultResponsibles->modelKeys(),
            'manual choice joins the PIC that was already in the pivot',
        );
    }

    public function test_category_default_pics_must_belong_to_the_destination_unit(): void
    {
        $it = Unit::create(['name' => 'IT']);
        $finance = Unit::create(['name' => 'Finance']);
        $admin = $this->unitUser('Admin Unit', $it, 'IT Category Admin');
        $itStaff = $this->unitUser('Staff Unit', $it, 'IT Category PIC');
        $financeStaff = $this->unitUser('Staff Unit', $finance, 'Finance Outsider');

        $this->assertSame(
            [$admin->id => $admin->name, $itStaff->id => $itStaff->name],
            ProblemCategory::defaultResponsibleOptions($it->id),
        );

        $this->actingAs($admin);

        Livewire::test(CreateProblemCategory::class)
            ->fillForm([
                'unit_id' => $it->id,
                'name' => 'Akses VPN',
                'default_responsibles' => [$itStaff->id, $financeStaff->id],
            ])
            ->call('create')
            // Aturan `in` multi-select berlaku per elemen; indeks 1 adalah staf Finance.
            ->assertHasFormErrors(['default_responsibles.1']);

        $this->assertFalse(ProblemCategory::query()->where('name', 'Akses VPN')->exists());

        Livewire::test(CreateProblemCategory::class)
            ->fillForm([
                'unit_id' => $it->id,
                'name' => 'Akses VPN',
                'default_responsibles' => [$itStaff->id, $admin->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $category = ProblemCategory::query()->where('name', 'Akses VPN')->firstOrFail();
        $this->assertSame([$admin->id, $itStaff->id], $category->defaultResponsibles->modelKeys());

        $this->actingAs($admin);
        Livewire::test(EditProblemCategory::class, ['record' => $category->getRouteKey()])
            ->assertFormSet(['default_responsibles' => [(string) $admin->id, (string) $itStaff->id]])
            ->fillForm(['default_responsibles' => [$itStaff->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([$itStaff->id], $category->fresh()->defaultResponsibles->modelKeys());
    }

    public function test_master_data_pages_open_their_actions_as_slide_over_modals(): void
    {
        if (! Schema::hasTable('unit_slas')) {
            Schema::create('unit_slas', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('unit_id');
                $table->unsignedBigInteger('priority_id');
                $table->integer('target_hours');
                $table->timestamps();
            });
        }

        $unit = Unit::create(['name' => 'IT']);
        $sla = UnitSla::create(['unit_id' => $unit->id, 'priority_id' => $this->priority->id, 'target_hours' => 24]);
        $entity = BusinessEntity::create(['name' => 'Complete Selular']);
        $open = TicketStatus::query()->findOrFail(TicketStatus::OPEN);
        $this->actingAs($this->user('Super Admin', 'Master Data Admin'));

        // Status tiket: dulu punya halaman Create/Edit/View dan relation manager.
        $this->assertSame(['index'], array_keys(TicketStatusResource::getPages()));
        $this->assertSame([], TicketStatusResource::getRelations());
        $this->assertDirectoryDoesNotExist(app_path('Filament/Resources/TicketStatusResource/RelationManagers'));

        $statuses = Livewire::test(ManageTicketStatuses::class)
            ->assertCanSeeTableRecords(TicketStatus::all());
        $this->assertActionsOpenAsMediumSlideOvers($statuses, [
            'create' => TestAction::make('create'),
            'view' => TestAction::make('view')->table($open),
            'edit' => TestAction::make('edit')->table($open),
        ]);
        $statuses
            ->callAction(TestAction::make('edit')->table($open), data: ['name' => 'Terbuka'])
            ->assertHasNoActionErrors();
        $this->assertSame('Terbuka', $open->fresh()->name);

        // SLA dan badan usaha sudah satu halaman; aksinya kini slide-over md.
        $this->assertSame(['index'], array_keys(UnitSlaResource::getPages()));
        $slas = Livewire::test(ManageUnitSlas::class)
            ->assertCanSeeTableRecords([$sla]);
        $this->assertActionsOpenAsMediumSlideOvers($slas, [
            'create' => TestAction::make('create'),
            'edit' => TestAction::make('edit')->table($sla),
        ]);
        $slas
            ->callAction(TestAction::make('edit')->table($sla), data: ['target_hours' => 8])
            ->assertHasNoActionErrors();
        $this->assertSame(8, (int) $sla->fresh()->target_hours);

        $this->assertSame(['index'], array_keys(BusinessEntityResource::getPages()));
        $entities = Livewire::test(ManageBusinessEntities::class)
            ->assertCanSeeTableRecords([$entity]);
        $this->assertActionsOpenAsMediumSlideOvers($entities, [
            'create' => TestAction::make('create'),
            'edit' => TestAction::make('edit')->table($entity),
        ]);
        $entities
            ->callAction(TestAction::make('create'), data: ['name' => 'Complete Mulia'])
            ->assertHasNoActionErrors();
        $this->assertTrue(BusinessEntity::query()->where('name', 'Complete Mulia')->exists());
    }

    /**
     * @param  array<string, TestAction>  $actions
     */
    private function assertActionsOpenAsMediumSlideOvers($page, array $actions): void
    {
        foreach ($actions as $name => $testAction) {
            $page->mountAction($testAction);
            $action = $page->instance()->getMountedAction();
            $this->assertInstanceOf(Action::class, $action, $name);
            $this->assertTrue($action->isModalSlideOver(), "{$name} opens as a slide-over");
            $this->assertSame(Width::Medium, $action->getModalWidth(), "{$name} uses the md width");
            $page->unmountAction();
        }
    }

    public function test_a_failing_mail_channel_does_not_block_whatsapp_and_bell_notifications(): void
    {
        // Produksi memakai queue sync: setiap notifikasi dieksekusi berurutan di
        // dalam commit transaksi. Email PIC yang gagal (SMTP timeout) tidak boleh
        // menggugurkan WA dan lonceng PIC maupun tanda terima WA ke pelapor.
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
        config()->set('services.whatsapp_gateway.url', 'https://waghub.test');
        config()->set('services.whatsapp_gateway.token', 'test-secret-token');
        $gateway = new RecordingWhatsAppGateway;
        $this->app->instance(WhatsAppGateway::class, $gateway);
        Notification::extend('mail', fn (): object => new class
        {
            public function send(object $notifiable, BaseNotification $notification): void
            {
                throw new TransportException('Connection to "ssl://smtp.example.test:465" timed out.');
            }
        });
        Log::spy();

        $origin = Unit::create(['name' => 'Warehouse']);
        $destination = Unit::create(['name' => 'IT']);
        $pic = $this->unitUser('Staff Unit', $destination, 'IT DND PIC');
        $pic->forceFill(['phone' => '081234567890'])->save();
        $reporter = $this->unitUser('User', $origin, 'Warehouse DND Reporter');
        $reporter->forceFill(['phone' => '081298765432'])->save();
        $category = ProblemCategory::create([
            'unit_id' => $destination->id,
            'name' => 'DND (Do And Done)',
        ]);
        $category->defaultResponsibles()->attach($pic->id);

        $this->actingAs($reporter);
        $ticket = DB::transaction(fn (): Ticket => Ticket::create([
            'priority_id' => $this->priority->id,
            'unit_id' => $destination->id,
            'owner_id' => $reporter->id,
            'problem_category_id' => $category->id,
            'title' => 'Hapus DND bulan November',
            'description' => 'Minta dibantu hapus DND yang sudah tersubmit.',
            'ticket_statuses_id' => TicketStatus::OPEN,
        ]));

        $this->assertSame($pic->id, (int) $ticket->fresh()->responsible_id);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $pic->id,
            'type' => NewTicketNotification::class,
        ]);
        $this->assertSame(
            ['081234567890', '081298765432'],
            array_column($gateway->messages, 'phone'),
            'WA ke PIC lalu tanda terima ke pelapor tetap terkirim walau email gagal',
        );
        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message): bool => str_starts_with($message, 'Gagal mengirim notifikasi Helpdesk'))
            ->atLeast()->once();
    }

    private function createMailboxAuthorizationSchema(): void
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

    /**
     * @return array<int, mixed>
     */
    private function downloadedSpreadsheetValues($component): array
    {
        $content = base64_decode((string) data_get($component->effects, 'download.content'), true);

        $this->assertNotFalse($content, 'The Livewire download payload must be valid base64.');

        $path = tempnam(sys_get_temp_dir(), 'ticket-export-');
        $this->assertNotFalse($path);

        try {
            file_put_contents($path, $content);

            return collect(IOFactory::load($path)
                ->getActiveSheet()
                ->toArray())
                ->flatten()
                ->filter(fn ($value): bool => $value !== null && $value !== '')
                ->values()
                ->all();
        } finally {
            @unlink($path);
        }
    }

    private function seedTicketPermissionsAndRoles(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([
            'ViewAny:Ticket',
            'View:Ticket',
            'Create:Ticket',
            'Update:Ticket',
            'Delete:Ticket',
            'ViewAny:ProblemCategory',
            'View:ProblemCategory',
            'Create:ProblemCategory',
            'Update:ProblemCategory',
            'Delete:ProblemCategory',
            'ViewAny:TicketStatus',
            'View:TicketStatus',
            'ViewAny:User',
            'View:User',
            'ViewAny:Comment',
            'View:Comment',
            'Create:Comment',
            'Update:Comment',
            'Delete:Comment',
            'ViewAny:UnitSla',
            'View:UnitSla',
            'Create:UnitSla',
            'Update:UnitSla',
            'Delete:UnitSla',
        ] as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'web']);
        }

        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_ticket_list_policy_checks_reuse_unit_memberships_instead_of_requerying_per_row(): void
    {
        $origin = Unit::create(['name' => 'Finance']);
        $destination = Unit::create(['name' => 'IT']);
        $staff = $this->unitUser('Staff Unit', $destination, 'IT Listing Staff');
        $reporter = $this->unitUser('User', $origin, 'Finance Listing Reporter');
        $category = ProblemCategory::create(['unit_id' => $destination->id, 'name' => 'Jaringan']);

        $this->actingAs($reporter);
        foreach (range(1, 8) as $i) {
            Ticket::create([
                'priority_id' => $this->priority->id,
                'unit_id' => $destination->id,
                'owner_id' => $reporter->id,
                'problem_category_id' => $category->id,
                'title' => "Tiket $i",
                'description' => "Tiket $i",
                'ticket_statuses_id' => TicketStatus::OPEN,
            ]);
        }

        $viewer = User::query()->findOrFail($staff->id);
        $this->actingAs($viewer);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $tickets = Ticket::query()->visibleTo($viewer)->latest()->limit(8)->get();
        $this->assertCount(8, $tickets);

        foreach ($tickets as $ticket) {
            $this->assertTrue(Gate::forUser($viewer)->allows('view', $ticket));
            Gate::forUser($viewer)->allows('update', $ticket);
            Gate::forUser($viewer)->allows('process', $ticket);
        }

        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(
            1,
            $queries->filter(fn (string $sql): bool => str_contains($sql, 'information_schema') || str_contains($sql, 'sqlite_master'))->count(),
            'keberadaan tabel user_entities paling banyak dicek sekali per request',
        );
        $this->assertSame(
            1,
            $queries->filter(fn (string $sql): bool => str_contains($sql, 'user_entities') && str_contains($sql, 'from "units"'))->count(),
            'keanggotaan divisi dimuat sekali lalu dipakai ulang untuk setiap baris',
        );
    }

    public function test_performance_index_migration_dedupes_memberships_and_adds_indexes(): void
    {
        $it = Unit::create(['name' => 'IT']);
        $busdev = Unit::create(['name' => 'BUSDEV']);
        $staff = $this->unitUser('Staff Unit', $it, 'IT Duplicate Member');
        // Skema produksi tidak punya unique di pivot ini; tiru kondisinya agar
        // dua attach bersamaan menghasilkan baris ganda seperti di MySQL.
        Schema::table('user_entities', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'entity_id', 'entity_type']);
        });
        $staff->units()->attach($it->id);
        $staff->units()->attach($busdev->id);
        $this->assertSame(3, DB::table('user_entities')->where('user_id', $staff->id)->count());

        $migration = require database_path('migrations/2026_11_09_000003_add_performance_indexes_and_membership_unique.php');
        $migration->up();

        $this->assertSame(2, DB::table('user_entities')->where('user_id', $staff->id)->count());
        $this->assertEqualsCanonicalizing([$it->id, $busdev->id], $staff->fresh()->assignedUnitIds());
        $this->assertTrue(Schema::hasIndex('user_entities', 'user_entities_user_entity_unique'));
        $this->assertTrue(Schema::hasIndex('tickets', 'tickets_unit_status_created_index'));
        $this->assertTrue(Schema::hasIndex('tickets', 'tickets_owner_created_index'));
        $this->assertTrue(Schema::hasIndex('tickets', 'tickets_responsible_status_index'));
        $this->assertTrue(Schema::hasIndex('ticket_histories', 'ticket_histories_ticket_created_index'));
        $this->assertTrue(Schema::hasIndex('users', 'users_identity_index'));
        $this->assertFalse(Schema::hasIndex('tickets', 'tickets_sla_due_at_index'), 'kolom SLA tidak ada di skema test, indeksnya dilewati');

        try {
            $staff->units()->attach($it->id);
            $this->fail('duplikat keanggotaan seharusnya ditolak oleh unique index');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        // Migrasi bisa diulang tanpa error dan bisa di-rollback.
        $migration->up();
        $migration->down();
        $this->assertFalse(Schema::hasIndex('user_entities', 'user_entities_user_entity_unique'));
        $this->assertFalse(Schema::hasIndex('tickets', 'tickets_unit_status_created_index'));
    }

    private function unitUser(string $role, Unit $unit, string $name): User
    {
        $user = $this->user($role, $name, $unit);
        $user->units()->attach($unit->id);

        return $user->refresh();
    }

    private function user(string $role, string $name, ?Unit $unit = null): User
    {
        $user = User::create([
            'unit_id' => $unit?->id,
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
            'email_verified_at' => now(),
            'email_verified_via' => 'admin',
            'password' => 'secret',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function ticket(
        User $owner,
        Unit $destination,
        string $title,
        int $status = TicketStatus::OPEN,
        ?User $responsible = null,
        ?ProblemCategory $category = null,
    ): Ticket {
        $category ??= ProblemCategory::create([
            'unit_id' => $destination->id,
            'name' => "Kategori {$title}",
        ]);

        Ticket::$isSeeding = true;

        try {
            return Ticket::create([
                'priority_id' => $this->priority->id,
                'unit_id' => $destination->id,
                'owner_id' => $owner->id,
                'problem_category_id' => $category->id,
                'title' => $title,
                'description' => 'Tiket lintas unit untuk pengujian mailbox.',
                'ticket_statuses_id' => $status,
                'responsible_id' => $responsible?->id,
            ]);
        } finally {
            Ticket::$isSeeding = false;
        }
    }
}
