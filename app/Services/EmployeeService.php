<?php

namespace App\Services;

use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EmployeeService
{
    protected ?array $employees = null;

    public function __construct()
    {
        $path = config('services.talenta.employee_file', base_path('talenta-list-employee.json'));

        if (! File::exists($path)) {
            Log::warning('File data karyawan Talenta tidak ditemukan. Auto-registrasi lewat direktori karyawan nonaktif.', [
                'path' => $path,
            ]);

            $this->employees = [];

            return;
        }

        $mtime = File::lastModified($path);
        $cacheKey = 'talenta_employees_data_'.$mtime;

        $this->employees = Cache::remember($cacheKey, 3600, function () use ($path) {
            $decoded = json_decode(File::get($path), true);

            if (! is_array($decoded) || ! is_array($decoded['data']['data'] ?? null)) {
                Log::error('File data karyawan Talenta tidak valid (struktur JSON tidak sesuai).', [
                    'path' => $path,
                ]);

                return [];
            }

            return $decoded['data']['data'];
        });
    }

    public function findByPhone(string $phone): ?array
    {
        if (! $this->employees) {
            return null;
        }

        $normalized = $this->normalizePhone($phone);
        foreach ($this->employees as $emp) {
            $mobile = $this->normalizePhone((string) ($emp['mobile_phone'] ?? ''));
            $altPhone = $this->normalizePhone((string) ($emp['phone'] ?? ''));

            if (($mobile !== '' && $mobile === $normalized) || ($altPhone !== '' && $altPhone === $normalized)) {
                return $emp;
            }
        }

        return null;
    }

    /**
     * Normalisasi ke format lokal (0xxx...) agar dapat dibandingkan
     * dengan nomor yang tersimpan di direktori Talenta.
     */
    protected function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (str_starts_with($phone, '62')) {
            return '0'.substr($phone, 2);
        }

        return $phone;
    }

    /**
     * Membuat user dari data karyawan Talenta.
     *
     * @throws DomainException ketika nomor HP tidak valid atau sudah dipakai user lain.
     */
    public function createUser(array $data, string $password): User
    {
        $phone = $this->normalizePhone((string) ($data['mobile_phone'] ?? ($data['phone'] ?? '')));

        if ($phone === '') {
            throw new DomainException('Data karyawan tidak memiliki nomor HP yang valid.');
        }

        $canonicalPhone = '62'.ltrim($phone, '0');
        if (User::query()->where('phone', $canonicalPhone)->exists()) {
            throw new DomainException('Nomor HP sudah terdaftar.');
        }

        $email = strtolower(trim((string) ($data['email'] ?? '')));

        // Anti duplicate email (Talenta might have weird placeholder emails)
        if ($email !== '' && User::where('email', $email)->exists()) {
            $email = 'emp_'.Str::random(6).'@placeholder.com';
        }

        return User::create([
            'name' => trim(strip_tags(($data['first_name'] ?? '').' '.($data['last_name'] ?? ''))),
            'email' => $email !== '' ? $email : null,
            'phone' => $canonicalPhone,
            'password' => Hash::make($password),
            'is_active' => true,
        ]);
    }
}
