<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $tables = config('permission.table_names');
        $columns = config('permission.column_names');
        $permissionKey = $columns['permission_pivot_key'] ?? 'permission_id';
        $roleKey = $columns['role_pivot_key'] ?? 'role_id';

        if (! Schema::hasTable($tables['permissions'])
            || ! Schema::hasTable($tables['roles'])
            || ! Schema::hasTable($tables['role_has_permissions'])) {
            return;
        }

        $permissionId = DB::table($tables['permissions'])
            ->where('name', 'Create:Ticket')
            ->where('guard_name', 'web')
            ->value('id');

        $roleIds = DB::table($tables['roles'])
            ->whereIn('name', ['Staff Unit', 'Staf Unit'])
            ->where('guard_name', 'web')
            ->pluck('id');

        if (! $permissionId || $roleIds->isEmpty()) {
            return;
        }

        foreach ($roleIds as $roleId) {
            DB::table($tables['role_has_permissions'])->insertOrIgnore([
                $permissionKey => $permissionId,
                $roleKey => $roleId,
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Intentionally irreversible: the grant may have existed before this migration.
    }
};
