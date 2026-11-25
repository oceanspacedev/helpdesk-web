<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Master sistem eksternal yang dipasang ke helpdesk (kontrak
     * docs/integrations/README.md). Token dan rahasia webhook disimpan
     * terenkripsi oleh cast model, sehingga kolomnya teks panjang.
     */
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('base_url', 500);
            $table->text('token')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('timeout_seconds')->default(15);
            $table->timestamp('last_checked_at')->nullable();
            $table->boolean('last_check_ok')->nullable();
            $table->string('last_check_message', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('integration_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('integration_id')
                ->constrained('integrations')
                ->cascadeOnDelete();
            $table->string('event_id', 191);
            $table->timestamp('received_at');
            $table->unique(['integration_id', 'event_id'], 'integration_events_event_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_events');
        Schema::dropIfExists('integrations');
    }
};
