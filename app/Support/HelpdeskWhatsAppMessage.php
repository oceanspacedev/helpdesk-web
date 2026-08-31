<?php

namespace App\Support;

use App\Models\Ticket;
use App\Models\User;

final class HelpdeskWhatsAppMessage
{
    public static function ticketNumber(Ticket $ticket): string
    {
        $year = $ticket->created_at?->format('Y') ?: now()->format('Y');

        return 'HD-'.$year.'-'.str_pad((string) $ticket->id, 5, '0', STR_PAD_LEFT);
    }

    public static function forReporter(Ticket $ticket, ?string $footer = null): string
    {
        $ticket->loadMissing(['unit', 'priority', 'businessEntity']);
        $number = self::ticketNumber($ticket);

        return self::compose(
            'Laporan diterima',
            $number,
            $ticket->title,
            self::destination($ticket),
            footer: $footer ?? 'Tim akan menindaklanjuti.',
        );
    }

    public static function forStaff(Ticket $ticket): string
    {
        $ticket->loadMissing(['unit', 'priority', 'businessEntity', 'owner']);
        $number = self::ticketNumber($ticket);
        $owner = $ticket->owner;
        $name = trim((string) ($owner?->name ?? ''));
        $contact = self::whatsappContact($owner, $number);

        $meta = implode("\n", array_filter([
            $name !== '' ? 'Pelapor: '.$name : null,
            $contact !== null ? "Koordinasi WA: {$contact['display']}\n{$contact['link']}" : null,
        ], static fn (?string $line): bool => $line !== null && $line !== ''));

        return self::compose(
            'Laporan baru',
            $number,
            $ticket->title,
            self::destination($ticket),
            meta: $meta !== '' ? $meta : null,
            url: url('/admin/tickets/'.$ticket->id),
        );
    }

    public static function inProgress(Ticket $ticket): string
    {
        return self::statusUpdate(
            $ticket,
            'Laporan diproses',
            'Tim sedang menangani laporan ini.',
        );
    }

    public static function cancelled(Ticket $ticket): string
    {
        return self::statusUpdate(
            $ticket,
            'Laporan dibatalkan',
            'Laporan ini tidak dilanjutkan.',
        );
    }

    public static function closed(Ticket $ticket): string
    {
        return self::statusUpdate(
            $ticket,
            'Laporan selesai',
            'Laporan ini sudah diselesaikan.',
        );
    }

    public static function statusUpdate(Ticket $ticket, string $heading, string $footer): string
    {
        $ticket->loadMissing(['unit', 'priority', 'businessEntity']);

        return self::compose(
            $heading,
            self::ticketNumber($ticket),
            $ticket->title,
            self::destination($ticket),
            footer: $footer,
        );
    }

    public static function comment(Ticket $ticket, string $comment): string
    {
        $ticket->loadMissing(['unit', 'priority', 'businessEntity']);

        $body = trim(html_entity_decode(strip_tags($comment)));

        return self::compose(
            'Komentar baru',
            self::ticketNumber($ticket),
            $ticket->title,
            self::destination($ticket),
            meta: $body !== '' ? $body : null,
            url: url('/admin/tickets/'.$ticket->id),
        );
    }

    public static function slaWarning(Ticket $ticket, string $dueFormatted, string $remaining): string
    {
        $ticket->loadMissing(['unit', 'priority', 'businessEntity']);

        return self::compose(
            'Tenggat SLA',
            self::ticketNumber($ticket),
            $ticket->title,
            self::destination($ticket),
            meta: 'Tenggat: '.$dueFormatted."\nSisa: ".$remaining,
            url: url('/admin/tickets/'.$ticket->id),
            footer: 'Segera ditindaklanjuti.',
        );
    }

    public static function compose(
        string $heading,
        string $number,
        string $title,
        string $destination = '',
        ?string $meta = null,
        ?string $url = null,
        ?string $footer = null,
    ): string {
        $lines = [
            '*Helpdesk*',
            '',
            $heading,
            '*'.$number.'*',
            '',
            trim($title) !== '' ? trim($title) : null,
            $destination !== '' ? $destination : null,
        ];

        if (filled($meta)) {
            $lines[] = '';
            $lines[] = $meta;
        }

        if (filled($url)) {
            $lines[] = '';
            $lines[] = $url;
        }

        if (filled($footer)) {
            $lines[] = '';
            $lines[] = $footer;
        }

        return implode("\n", array_values(array_filter(
            $lines,
            static fn (?string $line): bool => $line !== null,
        )));
    }

    private static function destination(Ticket $ticket): string
    {
        return implode(' · ', array_filter([
            trim((string) ($ticket->unit?->name ?? '')),
            trim((string) ($ticket->businessEntity?->name ?? '')),
            trim((string) ($ticket->priority?->name ?? '')),
        ]));
    }

    /**
     * @return array{display: string, link: string}|null
     */
    private static function whatsappContact(?User $user, string $ticketNumber): ?array
    {
        $canonical = PhoneNumber::canonical($user?->phone_normalized ?? $user?->phone);
        if ($canonical === null) {
            return null;
        }

        return [
            'display' => $canonical,
            'link' => self::waMe($canonical, $ticketNumber),
        ];
    }

    private static function waMe(string $canonical, ?string $ticketNumber = null): string
    {
        $url = 'https://wa.me/'.$canonical;
        if (filled($ticketNumber)) {
            $url .= '?text='.rawurlencode('Helpdesk '.$ticketNumber);
        }

        return $url;
    }
}
