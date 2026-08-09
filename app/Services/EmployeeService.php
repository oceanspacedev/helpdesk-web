<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;

class EmployeeService
{
    protected ?array $employees = null;

    public function __construct()
    {
        $this->employees = \Illuminate\Support\Facades\Cache::remember('talenta_employees_data', 3600, function () {
            $path = base_path('talenta-list-employee.json');
            if (File::exists($path)) {
                $json = File::get($path);
                $decoded = json_decode($json, true);
                return $decoded['data']['data'] ?? [];
            }
            return [];
        });
    }

    public function findByPhone(string $phone): ?array
    {
        if (!$this->employees) return null;

        $normalized = $this->normalizePhone($phone);
        foreach ($this->employees as $emp) {
            if ($this->normalizePhone((string)($emp['mobile_phone'] ?? '')) === $normalized) {
                return $emp;
            }
        }
        return null;
    }

    protected function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (str_starts_with($phone, '62')) return '0' . substr($phone, 2);
        return $phone;
    }

    public function createUser(array $data, string $password): User
    {
        $email = strtolower(trim((string)($data['email'] ?? '')));
        
        // Anti duplicate email (Talenta might have weird placeholder emails)
        if ($email !== '' && User::where('email', $email)->exists()) {
            $email = 'emp_' . \Illuminate\Support\Str::random(6) . '@placeholder.com';
        }

        return User::create([
            'name'  => trim(strip_tags(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''))),
            'email' => $email !== '' ? $email : null,
            'phone' => $data['mobile_phone'],
            'password' => Hash::make($password),
        ]);
    }
}
