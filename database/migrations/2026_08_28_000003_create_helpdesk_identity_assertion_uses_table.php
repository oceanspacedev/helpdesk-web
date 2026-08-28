<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('helpdesk_identity_assertion_uses', function (Blueprint $table): void {
            $table->id();
            $table->char('assertion_hash', 64)->unique();
            $table->char('intake_hash', 64);
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('helpdesk_identity_assertion_uses');
    }
};
