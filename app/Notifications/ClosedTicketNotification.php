<?php

namespace App\Notifications;

use App\Services\WhatsAppGateway;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ClosedTicketNotification extends Notification
{
    use Queueable;

    protected $ticket;

    /**
     * Create a new notification instance.
     */
    public function __construct($ticket)
    {
        $this->ticket = $ticket;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        // Kirim email notifikasi
        $mailMessage = (new MailMessage)
            ->subject('Permintaan Tiket Anda Telah Ditangani')
            ->line('Kami ingin memberitahukan bahwa tiket yang Anda ajukan ('.$this->ticket->title.') telah selesai ditangani. Anda dapat melihat detailnya dengan mengklik tautan di bawah ini.')
            ->action('Lihat Tiket', url('/admin/tickets/'.$this->ticket->id));

        // Kirim pesan WhatsApp setelah email dikirim
        $this->toWhatsapp($notifiable);

        return $mailMessage;
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'ticket_id' => $this->ticket->id,
            'ticket_statuses_id' => $this->ticket->ticket_statuses_id,
        ];
    }

    public function toWhatsapp($notifiable)
    {
        // Dapatkan nomor WhatsApp dari user yang akan dihubungi
        $phoneNumber = $notifiable->phone; // Asumsi bahwa nomor WhatsApp ada di field `phone`

        if (! $phoneNumber) {
            \Log::error('No phone number found for user: '.$notifiable->id);

            return;
        }

        // Format pesan WhatsApp
        $message = "🔔 *Notifikasi Penutupan Tiket* 🔔\n\n";
        $message .= 'Halo *'.$notifiable->name."*, kami ingin memberitahukan bahwa tiket yang Anda ajukan telah selesai ditangani.\n\n";
        $message .= '📝 ID Tiket: *'.$this->ticket->id."*\n";
        $message .= '📌 Subjek: *'.$this->ticket->title."*\n";
        $message .= '📅 Tanggal Ditutup: *'.now()->format('d M Y H:i')."*\n"; // Menggunakan waktu saat ini untuk tanggal penutupan
        $message .= '🔗 Lihat Tiket: '.url('/admin/tickets/'.$this->ticket->id)."\n\n";
        $message .= '— Bot';

        app(WhatsAppGateway::class)->send($phoneNumber, $message);
    }
}
