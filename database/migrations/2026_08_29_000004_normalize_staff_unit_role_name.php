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
        $roleKey = $columns['role_pivot_key'] ?? 'role_id';
        $permissionKey = $columns['permission_pivot_key'] ?? 'permission_id';

        if (! is_array($tables)
            || ! Schema::hasTable($tables['roles'])
            || ! Schema::hasTable($tables['model_has_roles'])
            || ! Schema::hasTable($tables['role_has_permissions'])) {
            return;
        }

        $legacyRoles = DB::table($tables['roles'])
            ->where('name', 'Staf Unit')
            ->get();

        foreach ($legacyRoles as $legacyRole) {
            $canonicalRole = DB::table($tables['roles'])
                ->where('name', 'Staff Unit')
                ->where('guard_name', $legacyRole->guard_name)
                ->first();

            if (! $canonicalRole) {
                $changes = ['name' => 'Staff Unit'];

                if (Schema::hasColumn($tables['roles'], 'updated_at')) {
                    $changes['updated_at'] = now();
                }

                DB::table($tables['roles'])
                    ->where('id', $legacyRole->id)
                    ->update($changes);

                $canonicalRoleId = (int) $legacyRole->id;
            } else {
                $canonicalRoleId = (int) $canonicalRole->id;

                DB::table($tables['role_has_permissions'])
                    ->where($roleKey, $legacyRole->id)
                    ->get()
                    ->each(function (object $permission) use (
                        $tables,
                        $roleKey,
                        $permissionKey,
                        $canonicalRoleId,
                    ): void {
                        DB::table($tables['role_has_permissions'])->insertOrIgnore([
                            $permissionKey => $permission->{$permissionKey},
                            $roleKey => $canonicalRoleId,
                        ]);
                    });

                DB::table($tables['model_has_roles'])
                    ->where($roleKey, $legacyRole->id)
                    ->get()
                    ->each(function (object $membership) use (
                        $tables,
                        $roleKey,
                        $canonicalRoleId,
                    ): void {
                        $attributes = (array) $membership;
                        $attributes[$roleKey] = $canonicalRoleId;

                        DB::table($tables['model_has_roles'])->insertOrIgnore($attributes);
                    });

                DB::table($tables['role_has_permissions'])
                    ->where($roleKey, $legacyRole->id)
                    ->delete();
                DB::table($tables['model_has_roles'])
                    ->where($roleKey, $legacyRole->id)
                    ->delete();
                DB::table($tables['roles'])
                    ->where('id', $legacyRole->id)
                    ->delete();
            }

            if (Schema::hasTable($tables['permissions'])) {
                $createTicketPermission = DB::table($tables['permissions'])
                    ->where('name', 'Create:Ticket')
                    ->where('guard_name', $legacyRole->guard_name)
                    ->value('id');

                if ($createTicketPermission) {
                    DB::table($tables['role_has_permissions'])->insertOrIgnore([
                        $permissionKey => $createTicketPermission,
                        $roleKey => $canonicalRoleId,
                    ]);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Intentionally irreversible: canonical and legacy memberships may have been merged.
    }
};
