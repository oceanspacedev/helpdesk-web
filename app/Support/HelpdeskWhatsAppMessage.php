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
        $number = self::ticketNumber($ticket);

        return self::compose(
            'Laporan diterima',
            $number,
            $ticket->title,
            self::destination($ticket),
            body: self::excerpt($ticket),
            footer: $footer ?? 'Tim akan menindaklanjuti.',
        );
    }

    public static function forStaff(Ticket $ticket): string
    {
        $ticket->loadMissing(['owner']);
        $number = self::ticketNumber($ticket);
        $owner = $ticket->owner;
        $name = trim((string) ($owner?->name ?? ''));
        $waLink = self::waMe($owner, $number);

        $meta = implode("\n", array_filter([
            $name !== '' ? '👤 '.$name : null,
            $waLink !== null ? '💬 '.$waLink : null,
        ], static fn (?string $line): bool => $line !== null && $line !== ''));

        return self::compose(
            'Laporan baru',
            $number,
            $ticket->title,
            self::destination($ticket),
            body: self::excerpt($ticket),
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
        $body = trim(html_entity_decode(strip_tags($comment), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

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
        return self::compose(
            'Tenggat SLA',
            self::ticketNumber($ticket),
            $ticket->title,
            self::destination($ticket),
            meta: 'Tenggat '.$dueFormatted.' · sisa '.$remaining,
            url: url('/admin/tickets/'.$ticket->id),
            footer: 'Segera ditindaklanjuti.',
        );
    }

    public static function compose(
        string $heading,
        string $number,
        string $title,
        string $destination = '',
        ?string $body = null,
        ?string $meta = null,
        ?string $url = null,
        ?string $footer = null,
    ): string {
        $lines = [
            '🛠️ *Helpdesk*',
            self::headingMark($heading).$heading.' · *'.$number.'*',
            '',
            self::boldTitle($title),
            $destination !== '' ? $destination : null,
        ];

        if (filled($body)) {
            $lines[] = '';
            $lines[] = $body;
        }

        if (filled($meta)) {
            $lines[] = '';
            $lines[] = $meta;
        }

        if (filled($url)) {
            $lines[] = '';
            $lines[] = '🔗 '.$url;
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
        $ticket->loadMissing(['unit', 'problemCategory', 'businessEntity', 'priority']);

        $priority = trim((string) ($ticket->priority?->name ?? ''));
        $parts = array_filter([
            trim((string) ($ticket->unit?->name ?? '')),
            trim((string) ($ticket->problemCategory?->name ?? '')),
            trim((string) ($ticket->businessEntity?->name ?? '')),
            $priority === '' ? '' : self::priorityMark($priority).$priority,
        ]);
        if ($parts === []) {
            return '';
        }

        return '🏢 '.implode(' · ', $parts);
    }

    private static function headingMark(string $heading): string
    {
        return match ($heading) {
            'Laporan diterima', 'Laporan selesai' => '✅ ',
            'Laporan baru' => '🎫 ',
            'Laporan diproses' => '⏳ ',
            'Laporan dibatalkan' => '❌ ',
            'Komentar baru' => '💬 ',
            'Tenggat SLA' => '⏰ ',
            default => '',
        };
    }

    private static function priorityMark(string $name): string
    {
        $key = mb_strtolower($name);

        return match (true) {
            str_contains($key, 'critical'), str_contains($key, 'urgent') => '🔴 ',
            str_contains($key, 'high'), str_contains($key, 'tinggi') => '🟠 ',
            str_contains($key, 'medium'), str_contains($key, 'sedang') => '🟡 ',
            str_contains($key, 'low'), str_contains($key, 'rendah') => '🟢 ',
            default => '',
        };
    }

    private static function boldTitle(string $title): ?string
    {
        $title = trim(str_replace('*', '', $title));

        return $title === '' ? null : '*'.$title.'*';
    }

    private static function excerpt(Ticket $ticket, int $max = 240): ?string
    {
        $text = html_entity_decode(strip_tags((string) $ticket->description), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/Dilaporkan via Form Publik/u', '', $text) ?? $text);
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '') {
            return null;
        }

        $title = trim((string) $ticket->title);
        if ($title !== '' && mb_strtolower($text) === mb_strtolower($title)) {
            return null;
        }

        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $max - 1)).'…';
    }

    private static function waMe(?User $user, ?string $ticketNumber = null): ?string
    {
        $canonical = PhoneNumber::canonical($user?->phone_normalized ?? $user?->phone);
        if ($canonical === null) {
            return null;
        }

        $url = 'https://wa.me/'.$canonical;
        if (filled($ticketNumber)) {
            $url .= '?text='.rawurlencode('Terkait '.$ticketNumber);
        }

        return $url;
    }
}
