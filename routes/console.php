<?php

use BezhanSalleh\FilamentExceptions\Models\Exception as FilamentException;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Peringatan SLA mengirim WhatsApp sinkron sebelum menandai tiket, jadi dua
// run yang tumpang tindih bisa mengirim dobel.
Schedule::command('sla:check-warnings')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// filament-exceptions menyimpan trace lengkap (puluhan KB per baris) dan
// hanya menyediakan retensi lewat model prunable, bukan jadwal.
Schedule::command('model:prune', ['--model' => [FilamentException::class]])
    ->daily()
    ->onOneServer();
Schedule::command('horizon:snapshot')->everyFiveMinutes();
