<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Satu kategori tiket boleh punya beberapa PIC default. Semua PIC
     * menerima notifikasi tiket baru dan tiketnya ditugaskan ke PIC yang
     * tiket aktifnya paling sedikit. Kolom tunggal
     * problem_categories.default_responsible_id dipindahkan ke tabel pivot
     * ini lalu dihapus.
     */
    public function up(): void
    {
        if (! Schema::hasTable('problem_category_responsibles')) {
            Schema::create('problem_category_responsibles', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('problem_category_id')
                    ->constrained('problem_categories')
                    ->cascadeOnDelete();
                $table->foreignId('user_id')
                    ->constrained('users')
                    ->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['problem_category_id', 'user_id'], 'problem_category_responsibles_unique');
            });
        }

        if (! Schema::hasColumn('problem_categories', 'default_responsible_id')) {
            return;
        }

        $this->moveSingleResponsiblesIntoPivot();

        Schema::table('problem_categories', function (Blueprint $table): void {
            $table->dropIndex('problem_categories_default_responsible_id_index');
            $table->dropColumn('default_responsible_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('problem_categories', 'default_responsible_id')) {
            Schema::table('problem_categories', function (Blueprint $table): void {
                $table->unsignedBigInteger('default_responsible_id')
                    ->nullable()
                    ->after('name')
                    ->index('problem_categories_default_responsible_id_index');
            });
        }

        // Kembali ke satu PIC per kategori: yang paling dulu ditambahkan.
        DB::table('problem_category_responsibles')
            ->orderBy('problem_category_id')
            ->orderBy('id')
            ->get(['problem_category_id', 'user_id'])
            ->unique('problem_category_id')
            ->each(function (object $row): void {
                DB::table('problem_categories')
                    ->where('id', $row->problem_category_id)
                    ->update(['default_responsible_id' => $row->user_id]);
            });

        Schema::dropIfExists('problem_category_responsibles');
    }

    private function moveSingleResponsiblesIntoPivot(): void
    {
        $now = now();

        DB::table('problem_categories')
            ->whereNotNull('default_responsible_id')
            ->whereExists(fn (Builder $query) => $query
                ->selectRaw('1')
                ->from('users')
                ->whereColumn('users.id', 'problem_categories.default_responsible_id'))
            ->orderBy('id')
            ->chunkById(200, function ($categories) use ($now): void {
                $rows = $categories->map(fn (object $category): array => [
                    'problem_category_id' => $category->id,
                    'user_id' => $category->default_responsible_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                DB::table('problem_category_responsibles')->insertOrIgnore($rows);
            });
    }
};
