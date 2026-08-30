<?php

namespace Tests\Feature;

use App\Filament\Resources\ProblemCategoryResource;
use App\Filament\Resources\ProblemCategoryResource\Pages\ViewProblemCategory;
use App\Filament\Resources\ProblemCategoryResource\RelationManagers\TicketsRelationManager;
use App\Filament\Resources\TicketResource\Pages\EditTicket;
use App\Filament\Resources\TicketResource\Pages\ListTickets;
use App\Filament\Resources\TicketResource\Pages\ViewTicket;
use App\Filament\Resources\TicketResource\RelationManagers\CommentsRelationManager;
use App\Filament\Resources\TicketStatusResource;
use App\Filament\Resources\TicketStatusResource\Pages\ViewTicketStatus;
use App\Filament\Resources\TicketStatusResource\RelationManagers\TicketsRelationManager as TicketStatusTicketsRelationManager;
use App\Filament\Resources\UnitResource;
use App\Filament\Resources\UnitResource\Pages\ViewUnit;
use App\Filament\Resources\UnitResource\RelationManagers\TicketsRelationManager as UnitTicketsRelationManager;
use App\Filament\Resources\UnitResource\RelationManagers\UsersRelationManager;
use App\Filament\Resources\UnitSlas\UnitSlaResource;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\ViewUser;
use App\Filament\Resources\UserResource\RelationManagers\TicketsRelationManager as UserTicketsRelationManager;
use App\Models\Comment;
use App\Models\Priority;
use App\Models\ProblemCategory;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\ClosedTicketNotification;
use App\Notifications\CommentNotification;
use App\Notifications\NewTicketNotification;
use App\Policies\TicketPolicy;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesHelpdeskIntegrationSchema;
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

    public function test_destination_processor_without_update_permission_can_view_but_cannot_update(): void
    {
        $origin = Unit::create(['name' => 'Finance']);
        $destination = Unit::create(['name' => 'IT']);
        $sender = $this->unitUser('Admin Unit', $origin, 'Finance Admin');
        $viewer = $this->unitUser('Staff Unit', $destination, 'IT Staff Read Only');
        $viewer->roles()->firstOrFail()->revokePermissionTo('Update:Ticket');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $viewer = $viewer->fresh();
        $ticket = $this->ticket($sender, $destination, 'Email finance bermasalah');

        $this->assertTrue($viewer->canProcessTickets());
        $this->assertFalse($viewer->can('Update:Ticket'));
        $this->assertTrue(Ticket::query()->incomingFor($viewer)->whereKey($ticket)->exists());
        $this->assertTrue(Ticket::query()->visibleTo($viewer)->whereKey($ticket)->exists());
        $this->assertTrue(Gate::forUser($viewer)->allows('view', $ticket));
        $this->assertFalse(Gate::forUser($viewer)->allows('update', $ticket));
        $this->assertFalse(Gate::forUser($viewer)->allows('process', $ticket));
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
        $this->assertTrue(UsersRelationManager::canViewForRecord($currentUnit, ViewUnit::class));
        $this->assertFalse(UsersRelationManager::canViewForRecord($legacyUnit, ViewUnit::class));
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
        $status = TicketStatus::create(['name' => 'Open']);
        $reporter = $this->unitUser('User', $unit, 'Assigned Reporter');
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

        $this->actingAs($reporter);

        $this->assertTrue(Gate::forUser($reporter)->allows('view', $category));

        Livewire::test(TicketsRelationManager::class, [
            'ownerRecord' => $category,
            'pageClass' => ViewProblemCategory::class,
        ])
            ->assertCanSeeTableRecords([$ownedTicket])
            ->assertCanNotSeeTableRecords([$otherTicket]);

        $this->assertSame(
            2,
            (int) TicketStatusResource::getEloquentQuery()->findOrFail($status->id)->tickets_count,
        );

        Livewire::test(TicketStatusTicketsRelationManager::class, [
            'ownerRecord' => $status,
            'pageClass' => ViewTicketStatus::class,
        ])
            ->assertCanSeeTableRecords([$ownedTicket, $reporterToFinance])
            ->assertCanNotSeeTableRecords([$otherTicket]);

        $this->actingAs($otherSender);

        Livewire::test(UserTicketsRelationManager::class, [
            'ownerRecord' => $reporter,
            'pageClass' => ViewUser::class,
        ])
            ->assertCanSeeTableRecords([$reporterToFinance])
            ->assertCanNotSeeTableRecords([$ownedTicket]);
    }

    public function test_unit_and_user_ticket_relations_are_registered_and_honor_mailbox_visibility(): void
    {
        $finance = Unit::create(['name' => 'Finance']);
        $it = Unit::create(['name' => 'IT']);
        $hr = Unit::create(['name' => 'HR']);
        $financeSender = $this->unitUser('Admin Unit', $finance, 'Finance Sender');
        $hrSender = $this->unitUser('Admin Unit', $hr, 'HR Sender');
        $itProcessor = $this->unitUser('Staff Unit', $it, 'IT Processor');
        $financeTicket = $this->ticket($financeSender, $it, 'Finance to IT');
        $hrTicket = $this->ticket($hrSender, $it, 'HR to IT');

        $this->assertContains(UnitTicketsRelationManager::class, UnitResource::getRelations());
        $this->assertContains(UserTicketsRelationManager::class, UserResource::getRelations());

        $this->actingAs($financeSender);

        $this->assertTrue(UnitTicketsRelationManager::canViewForRecord($it, ViewUnit::class));
        $this->assertTrue(UserTicketsRelationManager::canViewForRecord($financeSender, ViewUser::class));

        Livewire::test(UnitTicketsRelationManager::class, [
            'ownerRecord' => $it,
            'pageClass' => ViewUnit::class,
        ])
            ->assertCanSeeTableRecords([$financeTicket])
            ->assertCanNotSeeTableRecords([$hrTicket]);

        Livewire::test(UserTicketsRelationManager::class, [
            'ownerRecord' => $hrSender,
            'pageClass' => ViewUser::class,
        ])
            ->assertCanNotSeeTableRecords([$hrTicket]);

        $this->actingAs($itProcessor);

        Livewire::test(UnitTicketsRelationManager::class, [
            'ownerRecord' => $it,
            'pageClass' => ViewUnit::class,
        ])->assertCanSeeTableRecords([$financeTicket, $hrTicket]);

        $userWithoutTicketPermission = User::create([
            'name' => 'No Ticket Permission',
            'email' => 'no-ticket-permission@example.test',
            'is_active' => true,
        ]);
        $this->actingAs($userWithoutTicketPermission);

        $this->assertFalse(UnitTicketsRelationManager::canViewForRecord($it, ViewUnit::class));
        $this->assertFalse(UserTicketsRelationManager::canViewForRecord($financeSender, ViewUser::class));
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

        Notification::assertSentTo($sender, ClosedTicketNotification::class);
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
