<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bagian "Dari" pada tiket: divisi asal dan jabatan pelapor saat melapor.
     * Badan usaha pelapor ditambahkan terpisah (reporter_business_entity_id).
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->unsignedBigInteger('reporter_unit_id')
                ->nullable()
                ->after('owner_id')
                ->index('tickets_reporter_unit_id_index');
            $table->string('reporter_job_title', 150)->nullable()->after('reporter_unit_id');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropIndex('tickets_reporter_unit_id_index');
            $table->dropColumn(['reporter_unit_id', 'reporter_job_title']);
        });
    }
};
