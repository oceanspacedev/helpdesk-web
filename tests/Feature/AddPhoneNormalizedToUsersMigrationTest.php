<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AddPhoneNormalizedToUsersMigrationTest extends TestCase
{
    public function test_it_backfills_canonical_phone_without_rejecting_existing_format_duplicates(): void
    {
        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('phone', 20)->nullable()->unique();
        });

        DB::table('users')->insert([
            ['phone' => '0812-3456-7890'],
            ['phone' => '+62 812 3456 7890'],
            ['phone' => null],
        ]);

        $migration = require database_path('migrations/2026_08_28_000004_add_phone_normalized_to_users_table.php');
        $migration->up();

        $this->assertTrue(Schema::hasColumn('users', 'phone_normalized'));
        $this->assertSame([
            '6281234567890',
            '6281234567890',
            null,
        ], DB::table('users')->orderBy('id')->pluck('phone_normalized')->all());

        $index = collect(DB::select("PRAGMA index_list('users')"))
            ->firstWhere('name', 'users_phone_normalized_index');
        $this->assertNotNull($index);
        $this->assertSame(0, (int) $index->unique);

        $migration->down();
        $this->assertFalse(Schema::hasColumn('users', 'phone_normalized'));
    }
}
