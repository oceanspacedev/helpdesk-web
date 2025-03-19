<?php

namespace App\Notifications;

use GuzzleHttp\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CommentNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $comment;
    protected $ticket;

    /**
     * Buat instance notifikasi baru.
     */
    public function __construct($comment)
    {
        $this->comment = $comment;
        $this->ticket = $comment->ticket;
    }

    /**
     * Tentukan channel notifikasi.
     */
    public function via($notifiable): array
    {
        return ['mail'];
    }

    /**
     * Format email notifikasi.
     */
    public function toMail($notifiable): MailMessage
    {
        // Kirim email notifikasi
        $mailMessage = (new MailMessage)
            ->subject('Komentar Baru pada Tiket #' . $this->ticket->id)
            ->greeting('Halo ' . $notifiable->name . '!')
            ->line('Ada komentar baru pada tiket "' . $this->ticket->title . '".')
            ->line('Komentar: ' . html_entity_decode(strip_tags($this->comment->comment)) . '')
            ->action('Lihat Tiket', url('admin/tickets/' . $this->ticket->id));

        // Kirim pesan WhatsApp setelah email dikirim
        $this->toWhatsapp($notifiable);

        return $mailMessage;
    }

    /**
     * Array untuk notifikasi database (opsional).
     */
    public function toArray($notifiable): array
    {
        return [
            'comment_id' => $this->comment->id,
            'ticket_id' => $this->ticket->id,
            'comment' => $this->comment->comment,
        ];
    }

    /**
     * Mengirim notifikasi melalui WhatsApp.
     */
    public function toWhatsapp($notifiable)
    {
        // Dapatkan nomor WhatsApp user
        $phoneNumber = $notifiable->phone; // Asumsi field `phone` ada di tabel user

        if (!$phoneNumber) {
            \Log::error("No phone number found for user: " . $notifiable->id);
            return;
        }

        // Buat instance Guzzle Client
        $client = new Client();

        // Dapatkan endpoint API WhatsApp dari .env
        $apiEndpoint = env('WHATSAPP_API_ENDPOINT');

        // Format pesan WhatsApp
        $message = "🔔 *Notifikasi Komentar Baru* 🔔\n\n";
        $message .= "Halo *" . $notifiable->name . "*, ada komentar baru pada tiket Anda.\n\n";
        $message .= "📝 Tiket: *" . $this->ticket->title . "*\n";
        $message .= "💬 Komentar: *" . html_entity_decode(strip_tags($this->comment->comment)) . "*\n";
        $message .= "📅 Tanggal: *" . now()->format('d M Y H:i') . "*\n";
        $message .= "🔗 Lihat Tiket: " . url('admin/tickets/' . $this->ticket->id) . "\n\n";
        $message .= "— Bot";

        // Kirim request ke API WhatsApp
        try {
            $response = $client->post($apiEndpoint, [
                'query' => [
                    'apikey' => env('WHATSAPP_API_KEY'),
                    'sender' => env('WHATSAPP_SENDER_NUMBER'),
                    'receiver' => $phoneNumber,
                    'message' => $message,
                ]
            ]);

            if ($response->getStatusCode() !== 200) {
                \Log::error('Gagal mengirim pesan WhatsApp: ' . $response->getBody());
            }
        } catch (\Exception $e) {
            \Log::error('Gagal mengirim pesan WhatsApp: ' . $e->getMessage());
        }
    }
}
