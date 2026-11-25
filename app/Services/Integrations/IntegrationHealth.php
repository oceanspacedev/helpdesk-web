<?php

namespace App\Services\Integrations;

final class IntegrationHealth
{
    /**
     * @param  list<string>  $newCategories  kategori aktif sistem tujuan yang belum diimpor
     * @param  list<string>  $missingCategories  kategori tiket yang kodenya tidak ada lagi di sistem tujuan
     */
    public function __construct(
        public readonly bool $ok,
        public readonly ?bool $previousOk,
        public readonly string $message,
        public readonly array $newCategories = [],
        public readonly array $missingCategories = [],
    ) {}

    public function wentDown(): bool
    {
        return ! $this->ok && $this->previousOk !== false;
    }

    public function recovered(): bool
    {
        return $this->ok && $this->previousOk === false;
    }
}
