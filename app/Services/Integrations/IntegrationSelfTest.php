<?php

namespace App\Services\Integrations;

use App\Models\Integration;
use App\Models\IntegrationEvent;
use App\Models\ProblemCategory;
use App\Services\Integrations\External\ExternalFormSchema;
use App\Services\Integrations\External\ExternalNotFoundException;
use App\Services\Integrations\External\ExternalSystemClient;
use App\Services\Integrations\External\ExternalSystemException;

/**
 * Tes menyeluruh satu integrasi tanpa membuat tiket: koneksi dan token,
 * form setiap kategori yang dipakai unit, lalu webhook bolak-balik (sistem
 * eksternal mengirim "ping" bertanda tangan dan helpdesk memastikan ping itu
 * tiba serta lolos verifikasi). Dipakai "Tes koneksi" dan akhir wizard
 * "Hubungkan sistem".
 */
class IntegrationSelfTest
{
    private const MAX_FORMS = 30;

    public function __construct(
        private readonly IntegrationHealthCheck $healthCheck,
        private readonly ExternalFormSchema $forms,
    ) {}

    public function run(Integration $integration): IntegrationSelfTestResult
    {
        $health = $this->healthCheck->run($integration);
        $steps = [['label' => 'Koneksi & token', 'ok' => $health->ok, 'detail' => $health->message]];

        // Tanpa koneksi, langkah berikutnya pasti gagal dengan sebab yang sama.
        if (! $health->ok) {
            return new IntegrationSelfTestResult($health, $steps);
        }

        $steps[] = $this->formsStep($integration);
        $steps[] = $this->webhookStep($integration);

        return new IntegrationSelfTestResult($health, $steps);
    }

    /**
     * @return array{label: string, ok: ?bool, detail: string}
     */
    private function formsStep(Integration $integration): array
    {
        $keys = ProblemCategory::query()
            ->whereIn('unit_id', $integration->units()->pluck('id'))
            ->whereNotNull('external_key')
            ->orderBy('name')
            ->pluck('name', 'external_key')
            ->take(self::MAX_FORMS);

        if ($keys->isEmpty()) {
            return ['label' => 'Form kategori', 'ok' => null, 'detail' => 'Belum ada kategori yang dipetakan.'];
        }

        $client = ExternalSystemClient::for($integration);
        $broken = [];
        foreach ($keys as $key => $name) {
            try {
                if ($this->forms->sanitize($client->form((string) $key))['fields'] === []) {
                    $broken[] = "{$name} (form tanpa field)";
                }
            } catch (ExternalNotFoundException) {
                $broken[] = "{$name} (tidak ada di sistem tujuan)";
            } catch (ExternalSystemException $exception) {
                $broken[] = "{$name} ({$exception->getMessage()})";
            }
        }

        return $broken === []
            ? ['label' => 'Form kategori', 'ok' => true, 'detail' => $keys->count().' form dimuat dan valid.']
            : ['label' => 'Form kategori', 'ok' => false, 'detail' => 'Bermasalah: '.implode('; ', $broken).'.'];
    }

    /**
     * @return array{label: string, ok: ?bool, detail: string}
     */
    private function webhookStep(Integration $integration): array
    {
        try {
            $ping = ExternalSystemClient::for($integration)->testWebhook();
        } catch (ExternalNotFoundException) {
            return ['label' => 'Webhook', 'ok' => null, 'detail' => 'Sistem tujuan belum mendukung tes webhook.'];
        } catch (ExternalSystemException $exception) {
            return ['label' => 'Webhook', 'ok' => false, 'detail' => $exception->getMessage()];
        }

        if (! $ping['delivered']) {
            return ['label' => 'Webhook', 'ok' => false, 'detail' => $ping['error'] ?? 'Sistem tujuan gagal mengirim webhook ke helpdesk.'];
        }

        // Sistem tujuan mengaku terkirim; pastikan ping itu benar-benar tiba di helpdesk INI dan lolos tanda tangan.
        $arrived = $ping['event_id'] !== '' && IntegrationEvent::query()
            ->where('integration_id', $integration->id)
            ->where('event_id', $ping['event_id'])
            ->exists();

        return $arrived
            ? ['label' => 'Webhook', 'ok' => true, 'detail' => 'Ping dari sistem tujuan tiba dan tanda tangannya cocok.']
            : ['label' => 'Webhook', 'ok' => false, 'detail' => 'Sistem tujuan mengirim webhook ke alamat lain, bukan ke helpdesk ini. Hubungkan ulang dengan kode koneksi baru.'];
    }
}
