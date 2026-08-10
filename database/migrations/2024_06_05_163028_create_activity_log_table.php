<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateActivityLogTable extends Migration
{
    public function up()
    {
        // Gunakan config package jika tersedia (mis. sudah di-publish), fallback
        // ke nilai default karena package laravel-activitylog tidak wajib terinstall.
        Schema::connection(config('activitylog.database_connection'))
            ->create(config('activitylog.table_name', 'activity_log'), function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('log_name')->nullable();
                $table->text('description');
                $table->nullableMorphs('subject', 'subject');
                $table->nullableMorphs('causer', 'causer');
                $table->json('properties')->nullable();
                $table->timestamps();
                $table->index('log_name');
            });
    }

    public function down()
    {
        Schema::connection(config('activitylog.database_connection'))
            ->dropIfExists(config('activitylog.table_name', 'activity_log'));
    }
}
