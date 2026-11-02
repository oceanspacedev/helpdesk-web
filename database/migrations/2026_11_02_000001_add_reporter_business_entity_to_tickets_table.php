<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Badan usaha kontrak pelapor untuk bagian "Dari". Kolom
     * tickets.business_entities_id tetap berisi badan usaha yang dilaporkan,
     * yang bisa berbeda karena karyawan holding menangani banyak badan usaha.
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->unsignedBigInteger('reporter_business_entity_id')
                ->nullable()
                ->after('reporter_job_title')
                ->index('tickets_reporter_business_entity_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropIndex('tickets_reporter_business_entity_id_index');
            $table->dropColumn('reporter_business_entity_id');
        });
    }
};
