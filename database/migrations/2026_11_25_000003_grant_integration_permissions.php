<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Master Integrasi hanya untuk admin global. Permission Shield dibuat di
     * sini agar instalasi yang sudah berjalan tidak perlu menjalankan ulang
     * shield:generate; policy tetap mensyaratkan role Super/Master Admin.
     */
    private const PERMISSIONS = [
        'ViewAny:Integration',
        'View:Integration',
        'Create:Integration',
        'Update:Integration',
        'Delete:Integration',
    ];

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

        $now = now();
        foreach (self::PERMISSIONS as $name) {
            DB::table($tables['permissions'])->insertOrIgnore([
                'name' => $name,
                'guard_name' => 'web',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $permissionIds = DB::table($tables['permissions'])
            ->whereIn('name', self::PERMISSIONS)
            ->where('guard_name', 'web')
            ->pluck('id');
        $roleIds = DB::table($tables['roles'])
            ->whereIn('name', ['Super Admin', 'Master Admin'])
            ->where('guard_name', 'web')
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table($tables['role_has_permissions'])->insertOrIgnore([
                    $permissionKey => $permissionId,
                    $roleKey => $roleId,
                ]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Sengaja tidak dihapus: permission bisa saja sudah dibuat Shield.
    }
};
