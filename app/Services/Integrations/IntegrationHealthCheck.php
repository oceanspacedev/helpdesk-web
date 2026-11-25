<?php

namespace App\Services\Integrations;

use App\Models\Integration;
use App\Models\ProblemCategory;
use App\Services\Integrations\External\ExternalSystemClient;
use App\Services\Integrations\External\ExternalSystemException;
use Illuminate\Support\Str;

/**
 * Cek kesehatan satu integrasi: GET {base}/categories dengan token tersimpan,
 * lalu bandingkan kategori sistem tujuan dengan kategori tiket unit yang
 * memakainya. Hasilnya disimpan di last_check_* (kolom "Tes terakhir").
 * Dipakai "Tes koneksi" dan perintah terjadwal integrations:check.
 */
class IntegrationHealthCheck
{
    public function run(Integration $integration): IntegrationHealth
    {
        $previousOk = $integration->last_check_ok;

        try {
            $external = collect(ExternalSystemClient::for($integration)->categories());
        } catch (ExternalSystemException $exception) {
            return $this->record($integration, $previousOk, false, Str::limit($exception->getMessage(), 480));
        }

        $active = $external->where('active', true)->keyBy('key');
        $unitIds = $integration->units()->pluck('id');
        $mapped = ProblemCategory::query()
            ->whereIn('unit_id', $unitIds)
            ->whereNotNull('external_key')
            ->get(['name', 'external_key']);

        // Kategori yang sengaja dihapus admin tidak dianggap "baru".
        $known = ProblemCategory::withTrashed()
            ->whereIn('unit_id', $unitIds)
            ->whereNotNull('external_key')
            ->pluck('external_key');
        $new = $unitIds->isEmpty() ? collect() : $active->except($known->all())->pluck('name');
        $missing = $mapped->reject(fn (ProblemCategory $category): bool => $active->has($category->external_key))
            ->pluck('name')
            ->unique();

        $message = 'Terhubung. '.$external->count().' kategori ditemukan.';
        if ($new->isNotEmpty()) {
            $message .= ' '.$new->count().' kategori baru belum diimpor: '.$new->implode(', ').'.';
        }
        if ($missing->isNotEmpty()) {
            $message .= ' '.$missing->count().' kategori tidak ada lagi di sistem tujuan: '.$missing->implode(', ').'.';
        }

        return $this->record($integration, $previousOk, true, Str::limit($message, 480), $new->values()->all(), $missing->values()->all());
    }

    /**
     * @param  list<string>  $newCategories
     * @param  list<string>  $missingCategories
     */
    private function record(Integration $integration, ?bool $previousOk, bool $ok, string $message, array $newCategories = [], array $missingCategories = []): IntegrationHealth
    {
        $integration->forceFill([
            'last_checked_at' => now(),
            'last_check_ok' => $ok,
            'last_check_message' => $message,
        ])->save();

        return new IntegrationHealth($ok, $previousOk, $message, $newCategories, $missingCategories);
    }
}
