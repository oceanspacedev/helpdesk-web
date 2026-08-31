<?php

namespace App\Console\Commands;

use App\Models\Ticket;
use App\Models\User;
use App\Services\WhatsAppGateway;
use App\Support\HelpdeskWhatsAppMessage;
use Carbon\Carbon;
use Filament\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckSlaWarningCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sla:check-warnings';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cek dan kirim notifikasi peringatan SLA untuk tiket yang mendekati batas tenggat waktu (sisa <= 2 jam)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $now = Carbon::now();
        $threshold = Carbon::now()->addHours(2);

        // Cari tiket aktif yang mendekati SLA (sisa <= 2 jam) dan belum dikirimi peringatan
        $tickets = Ticket::whereNotIn('ticket_statuses_id', [3, 4])
            ->whereNotNull('sla_due_at')
            ->where('sla_due_at', '>', $now)
            ->where('sla_due_at', '<=', $threshold)
            ->whereNull('sla_warning_sent_at')
            ->with(['unit', 'priority', 'responsible', 'owner'])
            ->get();

        if ($tickets->isEmpty()) {
            $this->info('Tidak ada tiket yang mendekati batas SLA.');

            return Command::SUCCESS;
        }

        $whatsAppGateway = app(WhatsAppGateway::class);
        $count = 0;

        foreach ($tickets as $ticket) {
            $recipients = collect();
            $responsible = $ticket->eligibleResponsible();

            if ($responsible) {
                $recipients->push($responsible);
            } else {
                $recipients = User::query()
                    ->ticketProcessorsForUnit((int) $ticket->unit_id, includeGlobal: true)
                    ->get();
            }

            if ($recipients->isEmpty()) {
                continue;
            }

            $diffForHumans = $ticket->sla_due_at->diffForHumans();
            $dueFormatted = $ticket->sla_due_at->format('d M Y H:i');

            $waMessage = HelpdeskWhatsAppMessage::slaWarning(
                $ticket,
                $dueFormatted,
                $diffForHumans,
            );

            foreach ($recipients as $recipient) {
                // Send WhatsApp notification
                if (! empty($recipient->phone)) {
                    try {
                        $whatsAppGateway->send($recipient->phone, $waMessage);
                    } catch (\Throwable $e) {
                        Log::error("Gagal mengirim WhatsApp SLA warning untuk tiket #{$ticket->id}: ".$e->getMessage());
                    }
                }

                // Send Filament Database Notification
                try {
                    Notification::make()
                        ->warning()
                        ->title("⚠️ Peringatan SLA: Tiket #{$ticket->id}")
                        ->body("Tiket \"{$ticket->title}\" tersisa {$diffForHumans} sebelum batas SLA ({$dueFormatted}).")
                        ->actions([
                            NotificationAction::make('view')
                                ->button()
                                ->label('Lihat Tiket')
                                ->url('/admin/tickets/'.$ticket->id),
                        ])
                        ->sendToDatabase($recipient);
                } catch (\Throwable $e) {
                    Log::error("Gagal mengirim database notification untuk tiket #{$ticket->id}: ".$e->getMessage());
                }
            }

            // Tandai bahwa notifikasi peringatan sudah dikirim
            $ticket->update(['sla_warning_sent_at' => Carbon::now()]);
            $count++;
        }

        $this->info("Peringatan SLA berhasil dikirimkan untuk {$count} tiket.");

        return Command::SUCCESS;
    }
}
