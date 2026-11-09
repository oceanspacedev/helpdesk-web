<?php

use App\Models\ProblemCategory;
use App\Models\User;
use App\Services\Integrations\HelpdeskOperationalClassifier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PIC default per kategori tiket. Bila terisi, tiket baru kategori ini
     * langsung ditugaskan ke orang tersebut dan notifikasi tiket baru hanya
     * dikirim kepadanya, bukan ke seluruh anggota divisi tujuan.
     *
     * Pemetaan lama yang dikodekan keras untuk dokumen BUSDEV disalin ke
     * kolom ini agar terlihat dan bisa diubah dari halaman admin kategori.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('problem_categories', 'default_responsible_id')) {
            Schema::table('problem_categories', function (Blueprint $table): void {
                $table->unsignedBigInteger('default_responsible_id')
                    ->nullable()
                    ->after('name')
                    ->index('problem_categories_default_responsible_id_index');
            });
        }

        $this->backfillLegacyBusdevAssignees();
    }

    public function down(): void
    {
        Schema::table('problem_categories', function (Blueprint $table): void {
            $table->dropIndex('problem_categories_default_responsible_id_index');
            $table->dropColumn('default_responsible_id');
        });
    }

    private function backfillLegacyBusdevAssignees(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        DB::table('problem_categories')
            ->whereNull('default_responsible_id')
            ->whereNull('deleted_at')
            ->select(['id', 'unit_id', 'name'])
            ->orderBy('id')
            ->chunkById(200, function ($categories): void {
                foreach ($categories as $category) {
                    $assigneeName = HelpdeskOperationalClassifier::defaultAssigneeName((string) $category->name);
                    if ($assigneeName === null) {
                        continue;
                    }

                    $assignee = User::query()->where('name', $assigneeName)->first();
                    if (! $assignee?->isActiveTicketProcessorForUnit((int) $category->unit_id)) {
                        continue;
                    }

                    ProblemCategory::withTrashed()
                        ->whereKey($category->id)
                        ->update(['default_responsible_id' => $assignee->getKey()]);
                }
            });
    }
};
