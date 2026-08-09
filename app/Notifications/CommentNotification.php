<?php

namespace App\Notifications;

use App\Services\WhatsAppGateway;
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
        return ['mail', \App\Channels\WhatsAppChannel::class];
    }

    /**
     * Format email notifikasi.
     */
    public function toMail($notifiable): MailMessage
    {
        // Kirim email notifikasi
        return (new MailMessage)
            ->subject('Komentar Baru pada Tiket #'.$this->ticket->id)
            ->greeting('Halo '.$notifiable->name.'!')
            ->line('Ada komentar baru pada tiket "'.$this->ticket->title.'".')
            ->line('Komentar: '.html_entity_decode(strip_tags($this->comment->comment)).'')
            ->action('Lihat Tiket', url('admin/tickets/'.$this->ticket->id));
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
        // Format pesan WhatsApp
        $message = "🔔 *Notifikasi Komentar Baru* 🔔\n\n";
        $message .= 'Halo *'.$notifiable->name."*, ada komentar baru pada tiket Anda.\n\n";
        $message .= '📝 Tiket: *'.$this->ticket->title."*\n";
        $message .= '💬 Komentar: *'.html_entity_decode(strip_tags($this->comment->comment))."*\n";
        $message .= '📅 Tanggal: *'.now()->format('d M Y H:i')."*\n";
        $message .= '🔗 Lihat Tiket: '.url('admin/tickets/'.$this->ticket->id)."\n\n";
        $message .= '— Bot';

        return $message;
    }
}
