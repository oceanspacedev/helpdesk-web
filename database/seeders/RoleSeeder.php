<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // 1. Super Admin
        $superAdmin = Role::firstOrCreate([
            'name' => 'Super Admin',
            'guard_name' => 'web',
        ]);
        $superAdmin->syncPermissions(Permission::all());

        // 2. Admin Unit
        $adminUnit = Role::firstOrCreate([
            'name' => 'Admin Unit',
            'guard_name' => 'web',
        ]);
        $adminUnitPermissions = [
            'ViewAny:BusinessEntity', 'View:BusinessEntity', 'Create:BusinessEntity', 'Update:BusinessEntity', 'Delete:BusinessEntity',
            'ViewAny:ProblemCategory', 'View:ProblemCategory', 'Create:ProblemCategory', 'Update:ProblemCategory', 'Delete:ProblemCategory',
            'ViewAny:Ticket', 'View:Ticket', 'Create:Ticket', 'Update:Ticket', 'Delete:Ticket',
            'ViewAny:TicketStatus', 'View:TicketStatus',
            'ViewAny:Unit', 'View:Unit',
            'ViewAny:User', 'View:User',
            'View:SlaPerformanceWidget', 'View:SlaPerformanceChart', 'View:SlaLevelChart',
            'ViewAny:UnitSla', 'View:UnitSla',
            'ViewAny:Comment', 'View:Comment', 'Create:Comment', 'Update:Comment', 'Delete:Comment',
        ];
        $existingAdminUnitPerms = Permission::whereIn('name', $adminUnitPermissions)->get();
        $adminUnit->syncPermissions($existingAdminUnitPerms);

        // 3. Staff Unit
        $staffUnit = Role::firstOrCreate([
            'name' => 'Staff Unit',
            'guard_name' => 'web',
        ]);
        $staffUnitPermissions = [
            'ViewAny:BusinessEntity', 'View:BusinessEntity',
            'ViewAny:ProblemCategory', 'View:ProblemCategory',
            'ViewAny:Ticket', 'View:Ticket', 'Update:Ticket',
            'ViewAny:TicketStatus', 'View:TicketStatus',
            'ViewAny:Unit', 'View:Unit',
            'ViewAny:Comment', 'View:Comment', 'Create:Comment', 'Update:Comment', 'Delete:Comment',
        ];
        $existingStaffUnitPerms = Permission::whereIn('name', $staffUnitPermissions)->get();
        $staffUnit->syncPermissions($existingStaffUnitPerms);

        // 4. Master Admin
        $masterAdmin = Role::firstOrCreate([
            'name' => 'Master Admin',
            'guard_name' => 'web',
        ]);
        $masterAdminPermissions = [
            'ViewAny:BusinessEntity', 'View:BusinessEntity', 'Create:BusinessEntity', 'Update:BusinessEntity', 'Delete:BusinessEntity',
            'ViewAny:ProblemCategory', 'View:ProblemCategory', 'Create:ProblemCategory', 'Update:ProblemCategory', 'Delete:ProblemCategory',
            'ViewAny:Ticket', 'View:Ticket', 'Create:Ticket', 'Update:Ticket', 'Delete:Ticket',
            'ViewAny:TicketStatus', 'View:TicketStatus', 'Create:TicketStatus', 'Update:TicketStatus', 'Delete:TicketStatus',
            'ViewAny:Unit', 'View:Unit', 'Create:Unit', 'Update:Unit', 'Delete:Unit',
            'ViewAny:User', 'View:User', 'Create:User', 'Update:User', 'Delete:User',
            'ViewAny:UnitSla', 'View:UnitSla', 'Create:UnitSla', 'Update:UnitSla', 'Delete:UnitSla',
            'ViewAny:Comment', 'View:Comment', 'Create:Comment', 'Update:Comment', 'Delete:Comment',
        ];
        $existingMasterAdminPerms = Permission::whereIn('name', $masterAdminPermissions)->get();
        $masterAdmin->syncPermissions($existingMasterAdminPerms);

        // 5. User (Pelapor)
        $userRole = Role::firstOrCreate([
            'name' => 'User',
            'guard_name' => 'web',
        ]);
        $userPermissions = [
            'ViewAny:BusinessEntity', 'View:BusinessEntity',
            'ViewAny:ProblemCategory', 'View:ProblemCategory',
            'ViewAny:Ticket', 'View:Ticket', 'Create:Ticket',
            'ViewAny:TicketStatus', 'View:TicketStatus',
            'ViewAny:Unit', 'View:Unit',
            'ViewAny:Comment', 'View:Comment', 'Create:Comment', 'Update:Comment',
        ];
        $existingUserPerms = Permission::whereIn('name', $userPermissions)->get();
        $userRole->syncPermissions($existingUserPerms);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

