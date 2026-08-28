<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class HelpdeskIdentityHardeningMigrationsTest extends TestCase
{
    public function test_unique_phone_migration_stops_on_duplicates_then_enforces_the_canonical_key(): void
    {
        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('phone_normalized', 20)->nullable()->index('users_phone_normalized_index');
        });
        DB::table('users')->insert([
            ['phone_normalized' => '6281234567890'],
            ['phone_normalized' => '6281234567890'],
            ['phone_normalized' => null],
        ]);

        $migration = require database_path('migrations/2026_08_28_000005_enforce_unique_phone_normalized_on_users_table.php');

        try {
            $migration->up();
            $this->fail('Duplicate canonical phone must stop the migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('masih duplikat', $exception->getMessage());
        }

        DB::table('users')->where('id', 2)->delete();
        $migration->up();

        $index = collect(DB::select("PRAGMA index_list('users')"))
            ->firstWhere('name', 'users_phone_normalized_unique');
        $this->assertNotNull($index);
        $this->assertSame(1, (int) $index->unique);

        try {
            DB::table('users')->insert(['phone_normalized' => '6281234567890']);
            $this->fail('The database must reject a duplicate canonical phone.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $migration->down();
        $this->assertNull(collect(DB::select("PRAGMA index_list('users')"))
            ->firstWhere('name', 'users_phone_normalized_unique'));
    }

    public function test_legacy_email_timestamps_require_review_and_remember_tokens_are_revoked(): void
    {
        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->string('remember_token')->nullable();
        });
        DB::table('users')->insert([
            [
                'email_verified_at' => now(),
                'password' => 'verified-legacy-hash',
                'remember_token' => 'legacy-recaller-token',
            ],
            [
                'email_verified_at' => null,
                'password' => 'abandoned-pre-otp-hash',
                'remember_token' => 'unrelated-token',
            ],
        ]);

        $migration = require database_path('migrations/2026_08_28_000006_add_email_verification_source_to_users_table.php');
        $migration->up();

        $this->assertTrue(Schema::hasColumn('users', 'email_verified_via'));
        $this->assertDatabaseHas('users', [
            'id' => 1,
            'email_verified_via' => 'legacy_review_required',
            'remember_token' => null,
        ]);
        $this->assertDatabaseHas('users', [
            'id' => 2,
            'email_verified_via' => null,
            'password' => 'abandoned-pre-otp-hash',
            'remember_token' => null,
        ]);

        $migration->down();
        $this->assertFalse(Schema::hasColumn('users', 'email_verified_via'));
    }
}
