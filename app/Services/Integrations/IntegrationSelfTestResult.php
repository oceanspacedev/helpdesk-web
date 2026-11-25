<?php

namespace App\Services\Integrations;

final class IntegrationSelfTestResult
{
    /**
     * @param  list<array{label: string, ok: ?bool, detail: string}>  $steps  ok null = dilewati
     */
    public function __construct(
        public readonly IntegrationHealth $health,
        public readonly array $steps,
    ) {}

    public function passed(): bool
    {
        foreach ($this->steps as $step) {
            if ($step['ok'] === false) {
                return false;
            }
        }

        return true;
    }

    /** Satu baris per langkah, mis. "✓ Webhook: Ping ... cocok." */
    public function summary(): string
    {
        return implode("\n", array_map(fn (array $step): string => match ($step['ok']) {
            true => '✓',
            false => '✗',
            null => '–',
        }.' '.$step['label'].': '.$step['detail'], $this->steps));
    }
}
