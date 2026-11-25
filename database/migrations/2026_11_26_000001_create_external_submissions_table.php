<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jejak pengajuan yang diteruskan ke sistem eksternal (pass-through).
     * Isi pengajuan tetap hanya ada di sistem tujuan; helpdesk cukup
     * menyimpan kode referensi, judul, status terakhir, dan tautan lacak
     * supaya pelapor bisa membukanya lagi dari "Pengajuan Saya".
     */
    public function up(): void
    {
        Schema::create('external_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('integration_id')->constrained('integrations')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->string('category_name')->nullable();
            $table->string('external_ref', 191);
            $table->string('title', 250);
            $table->string('status_code', 32)->nullable();
            $table->string('status_label')->nullable();
            $table->string('track_url', 1000)->nullable();
            $table->timestamp('status_checked_at')->nullable();
            $table->timestamps();

            $table->unique(['integration_id', 'external_ref'], 'external_submissions_ref_unique');
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_submissions');
    }
};
