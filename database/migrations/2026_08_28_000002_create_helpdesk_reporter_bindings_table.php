<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('helpdesk_reporter_bindings', function (Blueprint $table): void {
            $table->id();
            $table->char('client_key', 64);
            $table->string('channel', 64);
            $table->char('external_user_hash', 64);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('verified_phone_hash', 64);
            $table->string('verification_method', 32);
            $table->timestamp('verified_at');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['client_key', 'channel', 'external_user_hash'],
                'helpdesk_reporter_binding_unique',
            );
            $table->index(['user_id', 'revoked_at'], 'helpdesk_reporter_binding_user_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('helpdesk_reporter_bindings');
    }
};
