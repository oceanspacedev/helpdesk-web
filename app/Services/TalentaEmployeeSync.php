<?php

namespace App\Services;

use App\Models\BusinessEntity;
use App\Models\Unit;
use App\Models\User;
use App\Support\TalentaEmployee;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Menyelaraskan akun Helpdesk dengan direktori karyawan Talenta:
 * badan usaha (branch), divisi (organization), jabatan (job), dan level (title).
 *
 * Mode preview menjalankan langkah yang sama di dalam transaksi lalu
 * membatalkannya, sehingga hasil preview identik dengan hasil apply.
 */
class TalentaEmployeeSync
{
    public const DEFAULT_ROLE = 'User';

    /** @var array<string, BusinessEntity> */
    private array $entities = [];

    /** @var array<string, Unit> */
    private array $units = [];

    /**
     * Daftar karyawan dari ekspor JSON Talenta: { "data": { "data": [...] } }.
     *
     * @return list<array<string, mixed>>|null null bila strukturnya tidak sesuai
     */
    public static function employeesFromJson(string $content): ?array
    {
        $employees = json_decode($content, true)['data']['data'] ?? null;

        return is_array($employees) ? array_values($employees) : null;
    }

    /**
     * Ringkasan hasil sinkron untuk command dan panel.
     *
     * @return array<string, int>
     */
    public static function summary(array $report, int $employeeCount): array
    {
        return [
            'Karyawan di file' => $employeeCount,
            'Badan usaha baru' => count($report['entities_created']),
            'Divisi baru' => count($report['divisions_created']),
            'Akun baru' => count($report['users_created']),
            'Akun diperbarui' => count($report['users_updated']),
            'Akun sudah sesuai' => $report['users_unchanged'],
            'Dilewati' => count($report['users_skipped']),
            'Dinonaktifkan (keluar)' => count($report['users_deactivated']),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $employees
     * @return array{
     *     entities_created: list<string>,
     *     divisions_created: list<string>,
     *     users_created: list<string>,
     *     users_updated: list<string>,
     *     users_unchanged: int,
     *     users_skipped: array<string, string>,
     *     users_deactivated: list<string>,
     * }
     */
    public function run(array $employees, bool $apply): array
    {
        $report = [
            'entities_created' => [],
            'divisions_created' => [],
            'users_created' => [],
            'users_updated' => [],
            'users_unchanged' => 0,
            'users_skipped' => [],
            'users_deactivated' => [],
        ];

        DB::beginTransaction();

        try {
            $this->loadMasterData();
            $rows = $this->rows($employees);
            $phoneOwners = $rows->flatMap(fn (array $row): array => $row['phones'])->countBy();
            $syncedIds = [];
            $now = now();

            foreach ($rows as $row) {
                $label = $row['name'].' ('.$row['nik'].')';
                $entity = $this->entity($row['branch'], $report);
                $unit = $this->unit($row['organization'], $report);

                $user = $this->match($row, $phoneOwners);
                if ($user === false) {
                    $report['users_skipped'][$label] = 'Nomor HP cocok dengan lebih dari satu akun Helpdesk.';

                    continue;
                }

                if ($user && in_array((int) $user->id, $syncedIds, true)) {
                    $report['users_skipped'][$label] = 'Akun Helpdesk-nya sudah dipakai karyawan lain di file ini.';

                    continue;
                }

                if ($user?->trashed()) {
                    $report['users_skipped'][$label] = 'Akun Helpdesk-nya sudah dihapus.';

                    continue;
                }

                if (! $user) {
                    $reason = $this->creationBlocker($row, $phoneOwners);
                    if ($reason !== null) {
                        $report['users_skipped'][$label] = $reason;

                        continue;
                    }

                    $user = User::query()->create([
                        'name' => $row['name'],
                        'email' => $this->availableEmail($row['email']),
                        'phone' => $row['phones'][0],
                        'password' => null,
                        'is_active' => true,
                    ]);
                    $report['users_created'][] = $label;
                    $changed = true;
                } else {
                    $changed = false;
                }

                $changed = $this->applyRow($user, $row, $entity, $unit, $now) || $changed;
                $syncedIds[] = (int) $user->id;

                if (! in_array($label, $report['users_created'], true)) {
                    if ($changed) {
                        $report['users_updated'][] = $label;
                    } else {
                        $report['users_unchanged']++;
                    }
                }
            }

            $report['users_deactivated'] = $this->deactivateLeavers(
                $rows->pluck('nik')->all(),
                $syncedIds,
            );

            $apply ? DB::commit() : DB::rollBack();
        } catch (\Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        return $report;
    }

    /**
     * Lengkapi profil satu akun dari data Talenta tanpa membuat master data
     * baru. Dipakai saat akun karyawan dibuat otomatis lewat login/laporan.
     *
     * @param  array<string, mixed>  $employee
     */
    public function applyProfile(User $user, array $employee): void
    {
        $this->loadMasterData();
        $row = $this->row($employee);
        if ($row === null) {
            return;
        }

        $this->applyRow(
            $user,
            $row,
            $this->entities[self::key($row['branch'])] ?? null,
            $this->units[$this->unitKey($row['organization'])] ?? null,
            now(),
        );
    }

    /**
     * @param  array{nik: string, name: string, branch: string, organization: string, job: ?string, level: ?string}  $row
     */
    private function applyRow(User $user, array $row, ?BusinessEntity $entity, ?Unit $unit, $now): bool
    {
        $previousUnitId = $user->unit_id ? (int) $user->unit_id : null;
        $wasSynced = $user->talenta_synced_at !== null;

        $user->forceFill(array_filter([
            'name' => $row['name'],
            'identity' => $row['nik'],
            'business_entity_id' => $entity?->id,
            'unit_id' => $unit?->id,
            'job_title' => $row['job'],
            'job_level' => $row['level'],
        ], fn ($value): bool => $value !== null));
        $changed = $user->isDirty();
        $user->talenta_synced_at = $now;
        $user->save();

        if ($unit) {
            // Divisi lama dilepas hanya bila dulu juga diisi dari Talenta, agar
            // keanggotaan yang diatur manual (mis. BUSDEV) tidak ikut hilang.
            if ($wasSynced && $previousUnitId && $previousUnitId !== (int) $unit->id) {
                $user->units()->detach($previousUnitId);
                $changed = true;
            }

            $attached = $user->units()->syncWithoutDetaching([(int) $unit->id])['attached'] ?? [];
            $changed = $changed || $attached !== [];
        }

        if ($user->roles()->doesntExist() && Role::query()->where('name', self::DEFAULT_ROLE)->exists()) {
            $user->assignRole(self::DEFAULT_ROLE);
            $changed = true;
        }

        return $changed;
    }

    /**
     * @return User|null|false false bila nomor HP cocok dengan lebih dari satu akun
     */
    private function match(array $row, Collection $phoneOwners): User|null|false
    {
        $byNik = User::query()->withTrashed()->where('identity', $row['nik'])->get();
        if ($byNik->count() === 1) {
            return $byNik->first();
        }

        // Nomor yang dipakai beberapa karyawan tidak bisa menunjuk satu akun.
        $phones = array_values(array_filter(
            $row['phones'],
            fn (string $phone): bool => $phoneOwners->get($phone, 0) === 1,
        ));
        if ($phones === []) {
            return null;
        }

        // Nomor HP adalah identitas login. Akun dengan nomor yang sama adalah
        // orang yang sama walaupun NIK-nya berubah (mis. kontrak baru).
        $byPhone = User::query()->withTrashed()->whereIn('phone_normalized', $phones)->get();

        return match ($byPhone->count()) {
            0 => null,
            1 => $byPhone->first(),
            default => false,
        };
    }

    private function creationBlocker(array $row, Collection $phoneOwners): ?string
    {
        return match (true) {
            $row['phones'] === [] => 'Tidak punya nomor HP yang valid di Talenta.',
            count($row['phones']) > 1 => 'Punya lebih dari satu nomor HP di Talenta.',
            $phoneOwners->get($row['phones'][0], 0) > 1 => 'Nomor HP-nya dipakai lebih dari satu karyawan di Talenta.',
            default => null,
        };
    }

    private function availableEmail(?string $email): ?string
    {
        if ($email === null || $email === '') {
            return null;
        }

        return User::query()->withTrashed()->where('email', $email)->exists() ? null : $email;
    }

    /**
     * Akun yang dulu berasal dari Talenta (punya NIK) tetapi sudah tidak ada
     * di data terbaru dinonaktifkan. Admin global tidak disentuh.
     *
     * @param  list<string>  $niks
     * @param  list<int>  $syncedIds
     * @return list<string>
     */
    private function deactivateLeavers(array $niks, array $syncedIds): array
    {
        $deactivated = [];

        User::query()
            ->whereNotNull('identity')
            ->whereNotIn('identity', $niks)
            ->whereNotIn('id', $syncedIds)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->reject(fn (User $user): bool => $user->hasGlobalTicketAccess())
            ->each(function (User $user) use (&$deactivated): void {
                $user->forceFill(['is_active' => false])->save();
                $deactivated[] = $user->name.' ('.$user->identity.')';
            });

        return $deactivated;
    }

    private function entity(string $name, array &$report): ?BusinessEntity
    {
        if ($name === '') {
            return null;
        }

        $key = self::key($name);
        if (! isset($this->entities[$key])) {
            $this->entities[$key] = BusinessEntity::query()->create(['name' => $name]);
            $report['entities_created'][] = $name;
        }

        return $this->entities[$key];
    }

    private function unit(string $name, array &$report): ?Unit
    {
        if ($name === '') {
            return null;
        }

        $key = $this->unitKey($name);
        if (! isset($this->units[$key])) {
            $this->units[$key] = Unit::query()->create(['name' => $name]);
            $report['divisions_created'][] = $name;
        }

        return $this->units[$key];
    }

    private function loadMasterData(): void
    {
        $this->entities = BusinessEntity::query()->orderBy('id')->get()
            ->reverse()
            ->keyBy(fn (BusinessEntity $entity): string => self::key($entity->name))
            ->all();
        $this->units = Unit::query()->orderBy('id')->get()
            ->reverse()
            ->keyBy(fn (Unit $unit): string => self::key($unit->name))
            ->all();
    }

    /**
     * Divisi Talenta yang namanya berbeda dengan divisi Helpdesk yang sudah
     * ada dipetakan lewat services.talenta.division_aliases.
     */
    private function unitKey(string $organization): string
    {
        $aliases = collect(config('services.talenta.division_aliases', []))
            ->mapWithKeys(fn (string $target, string $source): array => [self::key($source) => self::key($target)]);
        $key = self::key($organization);

        return $aliases->get($key, $key);
    }

    /**
     * @param  list<array<string, mixed>>  $employees
     * @return Collection<int, array{nik: string, name: string, email: ?string, phones: list<string>, branch: string, organization: string, job: ?string, level: ?string}>
     */
    private function rows(array $employees): Collection
    {
        return collect($employees)
            ->filter(fn (mixed $employee): bool => is_array($employee))
            ->map(fn (array $employee): ?array => $this->row($employee))
            ->filter()
            ->unique('nik')
            ->values();
    }

    private function row(array $employee): ?array
    {
        $nik = TalentaEmployee::identity($employee);
        $name = TalentaEmployee::name($employee);
        if ($nik === null || $name === null) {
            return null;
        }

        return [
            'nik' => $nik,
            'name' => $name,
            'email' => TalentaEmployee::email($employee) ?: null,
            'phones' => TalentaEmployee::normalizedPhones($employee),
            'branch' => self::clean($employee['branch'] ?? ''),
            'organization' => self::clean($employee['organization'] ?? ''),
            'job' => self::clean($employee['job'] ?? '') ?: null,
            'level' => self::clean($employee['title'] ?? '') ?: null,
        ];
    }

    private static function clean(mixed $value): string
    {
        return Str::squish(strip_tags((string) $value));
    }

    /**
     * "PT. Retail  Indonesia" dan "PT RETAIL INDONESIA" dianggap sama.
     */
    private static function key(string $name): string
    {
        return Str::of($name)->lower()->replace('.', ' ')->squish()->value();
    }
}
