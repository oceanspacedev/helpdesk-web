<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_ticket_creation_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('client_key', 64);
            $table->char('key_hash', 64);
            $table->char('request_hash', 64);
            $table->string('status', 16);
            $table->json('response')->nullable();
            $table->timestamps();

            $table->unique(
                ['client_key', 'key_hash'],
                'mcp_ticket_creation_idempotency_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_ticket_creation_requests');
    }
};
