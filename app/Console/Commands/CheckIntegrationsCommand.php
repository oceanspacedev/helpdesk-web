<?php

namespace App\Console\Commands;

use App\Filament\Resources\IntegrationResource;
use App\Models\Integration;
use App\Models\User;
use App\Services\Integrations\IntegrationHealth;
use App\Services\Integrations\IntegrationHealthCheck;
use App\Services\WhatsAppGateway;
use Filament\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Cek kesehatan semua integrasi aktif secara berkala. Admin global hanya
 * dikabari saat keadaannya berubah (terputus / pulih), bukan setiap kali
 * cek, supaya tidak ada notifikasi berulang selama gangguan berlangsung.
 */
class CheckIntegrationsCommand extends Command
{
    protected $signature = 'integrations:check';

    protected $description = 'Tes koneksi semua integrasi aktif dan kabari admin bila ada yang terputus atau pulih';

    public function handle(IntegrationHealthCheck $healthCheck): int
    {
        $integrations = Integration::query()->where('is_active', true)->orderBy('name')->get();

        foreach ($integrations as $integration) {
            $health = $healthCheck->run($integration);
            $this->line(($health->ok ? '[OK]    ' : '[GAGAL] ').$integration->name.': '.$health->message);

            if ($health->wentDown() || $health->recovered()) {
                $this->alertAdmins($integration, $health);
            }
        }

        return self::SUCCESS;
    }

    private function alertAdmins(Integration $integration, IntegrationHealth $health): void
    {
        $title = $health->ok
            ? "Integrasi {$integration->name} tersambung kembali"
            : "Integrasi {$integration->name} terputus";
        $body = $health->ok
            ? 'Pengajuan ke sistem ini bisa diteruskan lagi. '.$health->message
            : 'Pelapor tidak bisa mengirim tiket ke unit yang memakai sistem ini. Penyebab: '.$health->message;

        $admins = User::query()
            ->role(['Super Admin', 'Master Admin'])
            ->where('is_active', true)
            ->get();

        foreach ($admins as $admin) {
            if (filled($admin->phone)) {
                try {
                    app(WhatsAppGateway::class)->send($admin->phone, ($health->ok ? '✅ ' : '⚠️ ')."*{$title}*\n\n{$body}");
                } catch (\Throwable $exception) {
                    Log::warning('Gagal mengirim WhatsApp status integrasi.', ['integration_id' => $integration->id, 'error' => $exception->getMessage()]);
                }
            }

            try {
                Notification::make()
                    ->title($title)
                    ->body($body)
                    ->status($health->ok ? 'success' : 'danger')
                    ->actions([
                        NotificationAction::make('view')
                            ->button()
                            ->label('Buka Integrasi')
                            ->url(IntegrationResource::getUrl('index', panel: 'admin')),
                    ])
                    ->sendToDatabase($admin);
            } catch (\Throwable $exception) {
                Log::warning('Gagal menyimpan notifikasi status integrasi.', ['integration_id' => $integration->id, 'error' => $exception->getMessage()]);
            }
        }
    }
}
