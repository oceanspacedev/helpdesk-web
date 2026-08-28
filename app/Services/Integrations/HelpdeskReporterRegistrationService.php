<?php

namespace App\Services\Integrations;

use App\Models\User;
use App\Services\EmployeeService;
use App\Support\HelpdeskReporterName;
use App\Support\PhoneNumber;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class HelpdeskReporterRegistrationService
{
    public function __construct(
        private readonly EmployeeService $employees,
    ) {}

    /**
     * Create a restricted phone-only reporter after the caller has verified
     * control of the canonical WhatsApp number.
     *
     * @return array{ok: bool, status: string, message: string, user: ?User, created: bool}
     */
    public function createPhoneOnlyForVerifiedPhone(string $phone, string $name): array
    {
        $phone = PhoneNumber::canonical($phone);
        if ($phone === null) {
            return $this->failure('invalid_phone', 'Nomor WhatsApp terverifikasi tidak valid.');
        }

        $name = HelpdeskReporterName::normalize($name);
        if ($name === null) {
            return $this->failure('invalid_name', 'Nama lengkap belum valid.');
        }

        $lockKey = PhoneNumber::reporterWriteLockKey($phone);
        if ($lockKey === null) {
            return $this->failure('invalid_phone', 'Nomor WhatsApp terverifikasi tidak valid.');
        }

        try {
            return Cache::lock($lockKey, 30)->block(5, function () use ($phone, $name): array {
                return DB::transaction(function () use ($phone, $name): array {
                    $users = User::query()
                        ->withTrashed()
                        ->where('phone_normalized', $phone)
                        ->lockForUpdate()
                        ->get();

                    if ($users->count() > 1) {
                        return $this->failure(
                            'helpdesk_ambiguous',
                            'Nomor WhatsApp cocok dengan lebih dari satu akun Helpdesk. Hubungi administrator.',
                        );
                    }

                    $existing = $users->first();
                    if ($existing && ($existing->trashed() || ! $existing->is_active)) {
                        return $this->failure(
                            'helpdesk_inactive',
                            'Nomor WhatsApp terdaftar pada akun Helpdesk yang tidak aktif. Hubungi administrator.',
                        );
                    }
                    if ($existing) {
                        return $this->success('existing', $existing, false);
                    }

                    $employeeMatches = $this->employees->findAllByPhone($phone);
                    if (count($employeeMatches) > 1) {
                        return $this->failure(
                            'talenta_ambiguous',
                            'Nomor WhatsApp cocok dengan lebih dari satu data karyawan Talenta. Hubungi administrator.',
                        );
                    }
                    if ($employeeMatches !== []) {
                        return $this->failure(
                            'directory_changed',
                            'Data Talenta ditemukan atau berubah selama pendaftaran. Identitas akan diperiksa ulang.',
                        );
                    }

                    $user = User::query()->create([
                        'name' => $name,
                        'email' => null,
                        'phone' => $phone,
                        'password' => null,
                        'is_active' => true,
                    ]);

                    return $this->success('created_manual', $user, true);
                }, 3);
            });
        } catch (LockTimeoutException) {
            return $this->failure(
                'busy',
                'Pendaftaran nomor ini sedang diproses. Coba kirim nama Anda lagi sebentar lagi.',
            );
        } catch (QueryException $exception) {
            report($exception);

            // The unique canonical-phone index is the final cross-node guard.
            // If another safe flow won the race, reuse only its active account.
            $users = User::query()
                ->withTrashed()
                ->where('phone_normalized', $phone)
                ->get();
            $existing = $users->count() === 1 ? $users->first() : null;
            if ($existing && ! $existing->trashed() && $existing->is_active) {
                return $this->success('existing', $existing, false);
            }

            return $this->failure(
                'conflict',
                'Akun tidak dapat dibuat karena data nomor berubah atau sudah dipakai. Mulai ulang verifikasi.',
            );
        }
    }

    /**
     * @return array{ok: true, status: string, message: string, user: User, created: bool}
     */
    private function success(string $status, User $user, bool $created): array
    {
        return [
            'ok' => true,
            'status' => $status,
            'message' => $created ? 'Akun reporter berhasil dibuat.' : 'Akun reporter sudah tersedia.',
            'user' => $user,
            'created' => $created,
        ];
    }

    /**
     * @return array{ok: false, status: string, message: string, user: null, created: false}
     */
    private function failure(string $status, string $message): array
    {
        return [
            'ok' => false,
            'status' => $status,
            'message' => $message,
            'user' => null,
            'created' => false,
        ];
    }
}
