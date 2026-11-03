<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! array_key_exists('shield:generate', Artisan::all())) {
            Log::warning('Command shield:generate tidak tersedia. Filament Shield belum terinstall?');

            return;
        }

        try {
            Artisan::call('shield:generate', [
                '--all' => true,
                '--option' => 'policies_and_permissions',
                '--panel' => 'admin',
                '--ignore-existing-policies' => true,
            ]);
        } catch (\Throwable $e) {
            Log::error('Gagal generate permission via Filament Shield.', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
