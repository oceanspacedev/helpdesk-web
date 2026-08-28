<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('users')
            ->select('phone_normalized')
            ->whereNotNull('phone_normalized')
            ->groupBy('phone_normalized')
            ->havingRaw('COUNT(*) > 1')
            ->limit(10)
            ->pluck('phone_normalized');

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(
                'Migrasi dihentikan: users.phone_normalized masih duplikat. '
                .'Rapikan data dengan query audit sebelum deploy.',
            );
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_phone_normalized_index');
            $table->unique('phone_normalized', 'users_phone_normalized_unique');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_phone_normalized_unique');
            $table->index('phone_normalized', 'users_phone_normalized_index');
        });
    }
};
