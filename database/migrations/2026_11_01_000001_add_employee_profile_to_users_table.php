<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Profil karyawan dari Talenta: badan usaha, jabatan, dan level.
     * Divisi asal memakai kolom users.unit_id yang sudah ada.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('business_entity_id')
                ->nullable()
                ->after('unit_id')
                ->index('users_business_entity_id_index');
            $table->string('job_title', 150)->nullable()->after('identity');
            $table->string('job_level', 100)->nullable()->after('job_title');
            $table->timestamp('talenta_synced_at')->nullable()->after('job_level');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_business_entity_id_index');
            $table->dropColumn([
                'business_entity_id',
                'job_title',
                'job_level',
                'talenta_synced_at',
            ]);
        });
    }
};
