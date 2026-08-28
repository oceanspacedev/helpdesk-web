<?php

use App\Support\PhoneNumber;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone_normalized', 20)
                ->nullable()
                ->after('phone')
                ->index('users_phone_normalized_index');
        });

        DB::table('users')
            ->select(['id', 'phone'])
            ->orderBy('id')
            ->chunkById(500, function ($users): void {
                foreach ($users as $user) {
                    DB::table('users')
                        ->where('id', $user->id)
                        ->update([
                            'phone_normalized' => PhoneNumber::canonical($user->phone),
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_phone_normalized_index');
            $table->dropColumn('phone_normalized');
        });
    }
};
