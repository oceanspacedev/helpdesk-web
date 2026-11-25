<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Unit berintegrasi meneruskan tiket ke sistem eksternal. Helpdesk hanya
     * menyimpan tiket tipis: referensi eksternal, status cermin, dan snapshot
     * terakhir. Riwayat dari sistem eksternal tidak punya aktor helpdesk,
     * sehingga user_id riwayat boleh kosong.
     */
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table): void {
            $table->foreignId('integration_id')
                ->nullable()
                ->constrained('integrations')
                ->restrictOnDelete();
        });

        Schema::table('problem_categories', function (Blueprint $table): void {
            $table->string('external_key', 100)->nullable();
        });

        Schema::table('tickets', function (Blueprint $table): void {
            $table->foreignId('integration_id')
                ->nullable()
                ->constrained('integrations')
                ->restrictOnDelete();
            $table->string('external_ref', 100)->nullable();
            $table->string('external_status_code', 20)->nullable();
            $table->string('external_status_label', 150)->nullable();
            $table->json('external_snapshot')->nullable();
            $table->timestamp('external_synced_at')->nullable();
            $table->string('external_idempotency_key', 64)->nullable();
            $table->unique(['integration_id', 'external_ref'], 'tickets_integration_external_ref_unique');
            $table->unique(['integration_id', 'external_idempotency_key'], 'tickets_integration_idempotency_unique');
        });

        Schema::table('ticket_histories', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Foreign key dilepas lebih dulu: MySQL memakai indeks unik
        // (integration_id, ...) sebagai indeks foreign key.
        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropForeign(['integration_id']);
        });

        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropUnique('tickets_integration_external_ref_unique');
            $table->dropUnique('tickets_integration_idempotency_unique');
            $table->dropColumn([
                'integration_id',
                'external_ref',
                'external_status_code',
                'external_status_label',
                'external_snapshot',
                'external_synced_at',
                'external_idempotency_key',
            ]);
        });

        Schema::table('problem_categories', function (Blueprint $table): void {
            $table->dropColumn('external_key');
        });

        Schema::table('units', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('integration_id');
        });

        // ticket_histories.user_id sengaja dibiarkan nullable: riwayat dari
        // sistem eksternal tanpa aktor tidak boleh membuat rollback gagal.
    }
};
