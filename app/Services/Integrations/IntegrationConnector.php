<?php

namespace App\Services\Integrations;

use App\Models\Integration;
use App\Models\ProblemCategory;
use App\Models\Unit;
use App\Services\Integrations\External\ExternalNotFoundException;
use App\Services\Integrations\External\ExternalSystemClient;
use App\Services\Integrations\External\ExternalSystemException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Hubungkan sistem" dalam satu langkah: dari kode koneksi helpdesk membuat
 * (atau memperbarui) master Integrasi, memasang unit, mengimpor kategori
 * eksternal sebagai kategori tiket unit itu, lalu mendaftarkan webhook di
 * sistem eksternal (PUT {base}/webhook). Menggantikan pengisian manual di tiga
 * menu dan penyalinan token/webhook ke konfigurasi sistem eksternal.
 */
class IntegrationConnector
{
    /** @var array<string, list<array{key: string, name: string, active: bool, description: string, needs_observation: bool}>> */
    private array $categoryCache = [];

    /**
     * Kategori aktif sistem eksternal untuk kode ini (sekaligus tes koneksi).
     *
     * @return list<array{key: string, name: string, active: bool, description: string, needs_observation: bool}>
     *
     * @throws ExternalSystemException
     */
    public function categories(ConnectionCode $code): array
    {
        $cacheKey = hash('sha256', $code->baseUrl."\n".$code->token);

        return $this->categoryCache[$cacheKey] ??= array_values(array_filter(
            ExternalSystemClient::for($this->draft($code))->categories(),
            fn (array $category): bool => $category['active'],
        ));
    }

    /**
     * @param  list<string>  $categoryKeys
     *
     * @throws ExternalSystemException bila sistem eksternal tidak bisa dihubungi
     */
    public function connect(ConnectionCode $code, string $name, int $unitId, array $categoryKeys): ConnectionResult
    {
        $categories = collect($this->categories($code))->keyBy('key');
        $selected = $categories->only($categoryKeys);

        [$integration, $unit, $created, $mapped] = DB::transaction(function () use ($code, $name, $unitId, $selected): array {
            // Kode baru untuk sistem yang sama memperbarui integrasi yang ada,
            // supaya unit dan tiket lama tetap tersambung.
            $integration = Integration::query()->where('base_url', $code->baseUrl)->first() ?? new Integration;
            $integration->fill([
                'name' => trim($name) !== '' ? trim($name) : $code->name,
                'base_url' => $code->baseUrl,
                'token' => $code->token,
                'is_active' => true,
            ]);
            // Rahasia lama dipertahankan: sistem yang belum mendukung
            // pendaftaran webhook otomatis tetap memakai rahasia yang sama.
            if (blank($integration->webhook_secret)) {
                $integration->webhook_secret = Str::random(48);
            }
            $integration->timeout_seconds ??= Integration::DEFAULT_TIMEOUT_SECONDS;
            $integration->save();

            $unit = Unit::query()->findOrFail($unitId);
            $unit->forceFill(['integration_id' => $integration->id])->save();

            [$created, $mapped] = $this->importCategories($unit, $selected->all(), restoreDeleted: true);

            return [$integration, $unit, $created, $mapped];
        });

        $integration->forceFill([
            'last_checked_at' => now(),
            'last_check_ok' => true,
            'last_check_message' => 'Terhubung. '.$categories->count().' kategori ditemukan.',
        ])->save();

        return new ConnectionResult(
            integration: $integration,
            unit: $unit,
            createdCategories: $created,
            mappedCategories: $mapped,
            webhookError: $this->registerWebhook($integration),
        );
    }

    /**
     * Tambahkan kategori aktif sistem eksternal yang belum ada ke setiap unit
     * yang memakai integrasi ini. Kategori yang dihapus admin tidak
     * dipulihkan, dan kategori yang tidak ada lagi di sistem tujuan tidak
     * dihapus, hanya dilaporkan (IntegrationHealthCheck).
     *
     * @return array{created: list<string>, mapped: list<string>}
     *
     * @throws ExternalSystemException
     */
    public function syncCategories(Integration $integration): array
    {
        $categories = collect(ExternalSystemClient::for($integration)->categories())
            ->filter(fn (array $category): bool => $category['active'])
            ->keyBy('key')
            ->all();

        $created = [];
        $mapped = [];
        foreach ($integration->units()->orderBy('name')->get() as $unit) {
            [$unitCreated, $unitMapped] = DB::transaction(fn (): array => $this->importCategories($unit, $categories, restoreDeleted: false));
            $created = [...$created, ...array_map(fn (string $name): string => "{$name} ({$unit->name})", $unitCreated)];
            $mapped = [...$mapped, ...array_map(fn (string $name): string => "{$name} ({$unit->name})", $unitMapped)];
        }

        return ['created' => $created, 'mapped' => $mapped];
    }

    /**
     * @param  array<string, array{key: string, name: string}>  $categories  kategori eksternal per key
     * @return array{0: list<string>, 1: list<string>} [nama dibuat, nama lama yang dipetakan]
     */
    private function importCategories(Unit $unit, array $categories, bool $restoreDeleted): array
    {
        $created = [];
        $mapped = [];
        foreach ($categories as $key => $category) {
            $key = (string) $key;
            $existing = ProblemCategory::withTrashed()
                ->where('unit_id', $unit->id)
                ->where('external_key', $key)
                ->first()
                // Kategori yang sudah ada dengan nama sama dipetakan, bukan digandakan.
                ?? ProblemCategory::query()
                    ->where('unit_id', $unit->id)
                    ->whereNull('external_key')
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($category['name'])])
                    ->first();

            if ($existing === null) {
                ProblemCategory::query()->create(['unit_id' => $unit->id, 'name' => $category['name'], 'external_key' => $key]);
                $created[] = $category['name'];

                continue;
            }

            if ($existing->trashed()) {
                if (! $restoreDeleted) {
                    continue;
                }
                $existing->restore();
            }
            if ($existing->external_key !== $key) {
                $existing->forceFill(['external_key' => $key])->save();
                $mapped[] = $existing->name;
            }
        }

        return [$created, $mapped];
    }

    /** Null bila berhasil; selain itu alasan yang ditampilkan ke admin. */
    private function registerWebhook(Integration $integration): ?string
    {
        try {
            ExternalSystemClient::for($integration)->registerWebhook($integration->webhookUrl(), (string) $integration->webhook_secret);

            return null;
        } catch (ExternalNotFoundException) {
            return 'Sistem tujuan belum mendukung pendaftaran webhook otomatis.';
        } catch (ExternalSystemException $exception) {
            return $exception->getMessage();
        }
    }

    /** Integrasi sementara (tidak disimpan) untuk memanggil API sebelum terhubung. */
    private function draft(ConnectionCode $code): Integration
    {
        return new Integration([
            'name' => $code->name,
            'base_url' => $code->baseUrl,
            'token' => $code->token,
            'is_active' => true,
            'timeout_seconds' => Integration::DEFAULT_TIMEOUT_SECONDS,
        ]);
    }
}
