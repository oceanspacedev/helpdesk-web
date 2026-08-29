<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('helpdesk_mcp_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->longText('tokens')->nullable();
            $table->unsignedSmallInteger('intake_ttl_minutes')->default(30);
            $table->unsignedInteger('rate_limit_per_minute')->default(300);
            $table->longText('identity_pepper')->nullable();
            $table->longText('identity_assertion_secret')->nullable();
            $table->unsignedSmallInteger('identity_assertion_leeway_seconds')->default(300);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('helpdesk_mcp_settings');
    }
};
