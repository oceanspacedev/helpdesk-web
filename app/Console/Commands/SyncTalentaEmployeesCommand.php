<?php

namespace App\Console\Commands;

use App\Services\TalentaEmployeeSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class SyncTalentaEmployeesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'helpdesk:sync-employees
        {file? : File JSON ekspor Talenta (default: services.talenta.employee_file)}
        {--apply : Simpan perubahan. Tanpa opsi ini hanya preview}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Selaraskan akun Helpdesk dengan data karyawan Talenta: badan usaha, divisi, jabatan, dan karyawan yang keluar';

    public function handle(TalentaEmployeeSync $sync): int
    {
        $path = (string) ($this->argument('file') ?: config('services.talenta.employee_file'));
        if (! File::exists($path)) {
            $this->error("File tidak ditemukan: {$path}");

            return self::FAILURE;
        }

        $employees = json_decode(File::get($path), true)['data']['data'] ?? null;
        if (! is_array($employees)) {
            $this->error('Struktur JSON tidak sesuai. Harus { "data": { "data": [...] } } seperti ekspor Talenta.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $report = $sync->run($employees, $apply);

        $this->info($apply ? 'Sinkron disimpan.' : 'PREVIEW — belum ada yang disimpan. Jalankan ulang dengan --apply untuk menyimpan.');
        $this->newLine();

        $this->table(['Hasil', 'Jumlah'], [
            ['Karyawan di file', count($employees)],
            ['Badan usaha baru', count($report['entities_created'])],
            ['Divisi baru', count($report['divisions_created'])],
            ['Akun baru', count($report['users_created'])],
            ['Akun diperbarui', count($report['users_updated'])],
            ['Akun sudah sesuai', $report['users_unchanged']],
            ['Dilewati', count($report['users_skipped'])],
            ['Dinonaktifkan (keluar)', count($report['users_deactivated'])],
        ]);

        $this->listSection('Badan usaha baru', $report['entities_created']);
        $this->listSection('Divisi baru', $report['divisions_created']);
        $this->listSection('Dinonaktifkan karena tidak ada lagi di Talenta', $report['users_deactivated']);

        if ($report['users_skipped'] !== []) {
            $this->newLine();
            $this->warn('Dilewati:');
            foreach ($report['users_skipped'] as $employee => $reason) {
                $this->line("  - {$employee}: {$reason}");
            }
        }

        if ($this->output->isVerbose()) {
            $this->listSection('Akun baru', $report['users_created']);
            $this->listSection('Akun diperbarui', $report['users_updated']);
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $items
     */
    private function listSection(string $heading, array $items): void
    {
        if ($items === []) {
            return;
        }

        $this->newLine();
        $this->line("<comment>{$heading}:</comment>");
        foreach ($items as $item) {
            $this->line("  - {$item}");
        }
    }
}
