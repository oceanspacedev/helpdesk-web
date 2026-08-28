<?php

namespace App\Services;

use App\Models\User;
use App\Support\PhoneNumber;
use App\Support\TalentaEmployee;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

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
        $contentHash = hash_file('sha256', $path) ?: 'unreadable';
        $cacheKey = 'talenta_employees_data_'.hash('sha256', $path."\0".$mtime."\0".$contentHash);

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
        $matches = $this->findAllByPhone($phone);
        if (count($matches) > 1) {
            Log::warning('Nomor telepon cocok dengan lebih dari satu karyawan Talenta; lookup ditolak.', [
                'phone_hash' => hash('sha256', (string) $this->normalizePhone($phone)),
                'match_count' => count($matches),
            ]);
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAllByPhone(string $phone): array
    {
        if (! $this->employees) {
            return [];
        }

        $normalized = $this->normalizePhone($phone);
        if ($normalized === null) {
            return [];
        }

        return array_values(array_filter($this->employees, function (mixed $employee) use ($normalized): bool {
            if (! is_array($employee)) {
                return false;
            }

            $mobile = $this->normalizePhone((string) ($employee['mobile_phone'] ?? ''));
            $altPhone = $this->normalizePhone((string) ($employee['phone'] ?? ''));

            return ($mobile !== null && $mobile === $normalized)
                || ($altPhone !== null && $altPhone === $normalized);
        }));
    }

    /**
     * Normalisasi ke format kanonik yang sama dengan akun Helpdesk.
     */
    protected function normalizePhone(string $phone): ?string
    {
        return PhoneNumber::canonical($phone);
    }

    /**
     * Membuat user dari data karyawan Talenta.
     *
     * @throws DomainException ketika nomor HP tidak valid atau sudah dipakai user lain.
     */
    public function createUser(array $data, ?string $password = null, ?string $verifiedPhone = null): User
    {
        $employeePhones = TalentaEmployee::normalizedPhones($data);

        if ($verifiedPhone !== null) {
            $canonicalPhone = $this->normalizePhone($verifiedPhone);
            if ($canonicalPhone === null || ! in_array($canonicalPhone, $employeePhones, true)) {
                throw new DomainException('Nomor HP terverifikasi tidak cocok dengan data karyawan.');
            }
        } else {
            if (count($employeePhones) !== 1) {
                throw new DomainException($employeePhones === []
                    ? 'Data karyawan tidak memiliki nomor HP yang valid.'
                    : 'Data karyawan memiliki lebih dari satu nomor HP. Nomor terverifikasi wajib ditentukan.');
            }

            $canonicalPhone = $employeePhones[0];
        }

        if (User::query()->withTrashed()->where('phone_normalized', $canonicalPhone)->exists()) {
            throw new DomainException('Nomor HP sudah terdaftar.');
        }

        $name = TalentaEmployee::name($data);
        if ($name === null) {
            throw new DomainException('Data karyawan tidak memiliki nama yang valid.');
        }

        $email = TalentaEmployee::email($data);

        // Jangan membuat alamat email sintetis: notifikasi dapat bocor ke domain pihak lain.
        if ($email !== '' && User::query()->withTrashed()->where('email', $email)->exists()) {
            $email = '';
        }

        return User::create([
            'name' => $name,
            'email' => $email !== '' ? $email : null,
            'phone' => $canonicalPhone,
            'password' => filled($password) ? Hash::make($password) : null,
            'identity' => TalentaEmployee::identity($data),
            'is_active' => true,
        ]);
    }
}
