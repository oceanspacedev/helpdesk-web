<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PhoneOnlyReporterEmailMigrationTest extends TestCase
{
    public function test_it_preserves_the_existing_unique_email_index_when_making_email_nullable(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
        });

        $migrationPaths = glob(database_path('migrations/*_reporters_without_email.php')) ?: [];
        $this->assertCount(1, $migrationPaths);

        $migration = require $migrationPaths[0];
        $migration->up();

        $uniqueIndexes = collect(DB::select("PRAGMA index_list('users')"))
            ->filter(fn (object $index): bool => (int) $index->unique === 1);
        $emailColumn = collect(DB::select("PRAGMA table_info('users')"))
            ->firstWhere('name', 'email');

        $this->assertCount(1, $uniqueIndexes);
        $this->assertSame(0, (int) $emailColumn->notnull);
    }
}
