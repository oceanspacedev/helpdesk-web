<?php

namespace App\Services\Integrations\External;

use App\Models\TicketStatus;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Validasi dan normalisasi TicketSnapshot kontrak v1. Snapshot yang tidak
 * punya ref, kode status yang dikenal, atau updated_at yang valid ditolak.
 */
final class ExternalTicketSnapshot
{
    public const STATUS_MAP = [
        'open' => TicketStatus::OPEN,
        'in_progress' => TicketStatus::IN_PROGRESS,
        'done' => TicketStatus::CLOSED,
        'cancelled' => TicketStatus::CANCEL,
    ];

    /**
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException
     */
    public static function normalize(mixed $data): array
    {
        if (! is_array($data)) {
            throw new \InvalidArgumentException('Snapshot tiket bukan objek.');
        }

        $ref = is_scalar($data['ref'] ?? null) ? trim((string) $data['ref']) : '';
        if ($ref === '' || mb_strlen($ref) > 100) {
            throw new \InvalidArgumentException('Snapshot tiket tidak memiliki ref yang valid.');
        }

        $code = is_scalar(data_get($data, 'status.code')) ? (string) data_get($data, 'status.code') : '';
        if (! array_key_exists($code, self::STATUS_MAP)) {
            throw new \InvalidArgumentException('Kode status snapshot tidak dikenal.');
        }

        $updatedAt = self::parseTime($data['updated_at'] ?? null);
        if ($updatedAt === null) {
            throw new \InvalidArgumentException('Snapshot tiket tidak memiliki updated_at yang valid.');
        }

        $label = is_scalar(data_get($data, 'status.label')) ? trim((string) data_get($data, 'status.label')) : '';

        return [
            'ref' => $ref,
            'url' => is_string($data['url'] ?? null) ? $data['url'] : null,
            'status' => [
                'code' => $code,
                'label' => $label !== '' ? mb_substr($label, 0, 150) : $code,
            ],
            'summary' => self::listOf($data['summary'] ?? []),
            'items' => self::listOf($data['items'] ?? []),
            'timeline' => self::listOf($data['timeline'] ?? []),
            'comments' => self::listOf($data['comments'] ?? []),
            'files' => self::listOf($data['files'] ?? []),
            'updated_at' => (string) $data['updated_at'],
        ];
    }

    public static function statusId(string $code): int
    {
        return self::STATUS_MAP[$code] ?? TicketStatus::OPEN;
    }

    public static function parseTime(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Id komentar baru di $next yang belum ada di $previous.
     *
     * @param  array<string, mixed>|null  $previous
     * @param  array<string, mixed>  $next
     * @return list<array<string, mixed>>
     */
    public static function newComments(?array $previous, array $next): array
    {
        $known = [];
        foreach ((array) ($previous['comments'] ?? []) as $comment) {
            $known[self::commentKey($comment)] = true;
        }

        $new = [];
        foreach ((array) ($next['comments'] ?? []) as $comment) {
            if (! is_array($comment) || isset($known[self::commentKey($comment)])) {
                continue;
            }

            $new[] = $comment;
        }

        return $new;
    }

    private static function commentKey(mixed $comment): string
    {
        if (! is_array($comment)) {
            return '';
        }

        $id = $comment['id'] ?? null;
        if (is_scalar($id) && (string) $id !== '') {
            return 'id:'.$id;
        }

        return 'hash:'.sha1(json_encode([$comment['author'] ?? null, $comment['body'] ?? null, $comment['at'] ?? null]) ?: '');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function listOf(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }
}
