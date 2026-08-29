<?php

use App\Models\Unit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasTable('user_entities')) {
            return;
        }

        DB::table('users')
            ->whereNotNull('unit_id')
            ->select(['id', 'unit_id'])
            ->orderBy('id')
            ->chunkById(500, function ($users): void {
                foreach ($users as $user) {
                    $hasUnitMembership = DB::table('user_entities')
                        ->where('user_id', $user->id)
                        ->where('entity_type', Unit::class)
                        ->exists();

                    if (! $hasUnitMembership) {
                        DB::table('user_entities')->insert([
                            'user_id' => $user->id,
                            'entity_type' => Unit::class,
                            'entity_id' => $user->unit_id,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Data lama yang sudah menjadi relasi aktif tidak aman untuk dibedakan
        // dari relasi yang dibuat manual, sehingga rollback tidak menghapusnya.
    }
};
