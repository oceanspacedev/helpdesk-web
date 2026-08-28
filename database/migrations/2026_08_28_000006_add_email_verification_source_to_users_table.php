<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('email_verified_via', 32)
                ->nullable()
                ->after('email_verified_at');
        });

        // Versi PhoneLogin lama menandai email verified setelah OTP nomor.
        // Timestamp lama tidak membuktikan kepemilikan email dan wajib ditinjau.
        // Revoke every legacy recaller token. The previous phone flow could
        // persist a credential before OTP and create a remember cookie.
        DB::table('users')->update(['remember_token' => null]);

        DB::table('users')
            ->whereNotNull('email_verified_at')
            ->update(['email_verified_via' => 'legacy_review_required']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('email_verified_via');
        });
    }
};
