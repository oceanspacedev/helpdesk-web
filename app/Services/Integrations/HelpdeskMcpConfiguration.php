<?php

namespace App\Services\Integrations;

use App\Models\HelpdeskMcpSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HelpdeskMcpConfiguration
{
    private bool $settingsResolved = false;

    private ?HelpdeskMcpSetting $settings = null;

    /**
     * @return list<string>
     */
    public function tokens(): array
    {
        return collect($this->tokenRecords())
            ->filter(fn (array $token): bool => (bool) $token['active'])
            ->pluck('token')
            ->values()
            ->all();
    }

    /**
     * @return list<array{name: string, token: string, active: bool}>
     */
    public function tokenRecords(): array
    {
        $settings = $this->databaseSettings();

        if ($settings) {
            return $this->normalizeTokenRecords($settings->tokens ?? []);
        }

        return $this->normalizeTokenRecords(
            config('services.helpdesk_mcp.tokens', []),
        );
    }

    public function intakeTtlMinutes(): int
    {
        return max(10, (int) ($this->databaseSettings()?->intake_ttl_minutes
            ?? config('services.helpdesk_mcp.intake_ttl_minutes', 30)));
    }

    public function rateLimitPerMinute(): int
    {
        return min(600, max(60, (int) ($this->databaseSettings()?->rate_limit_per_minute
            ?? config('services.helpdesk_mcp.rate_limit_per_minute', 300))));
    }

    public function identityAssertionLeewaySeconds(): int
    {
        return max(30, (int) ($this->databaseSettings()?->identity_assertion_leeway_seconds
            ?? config('services.helpdesk_mcp.identity_assertion_leeway_seconds', 300)));
    }

    public function identityPepper(): string
    {
        $settings = $this->databaseSettings();
        if ($settings && $settings->identity_pepper !== null) {
            $pepper = trim((string) $settings->identity_pepper);

            return $pepper !== '' ? $pepper : (string) config('app.key');
        }

        $pepper = trim((string) config('services.helpdesk_mcp.identity_pepper', ''));

        return $pepper !== '' ? $pepper : (string) config('app.key');
    }

    public function identityAssertionSecret(): string
    {
        $settings = $this->databaseSettings();
        if ($settings && $settings->identity_assertion_secret !== null) {
            return trim((string) $settings->identity_assertion_secret);
        }

        return trim((string) config('services.helpdesk_mcp.identity_assertion_secret', ''));
    }

    public function identityPepperSource(): string
    {
        $settings = $this->databaseSettings();
        if ($settings && $settings->identity_pepper !== null) {
            return trim((string) $settings->identity_pepper) !== '' ? 'database' : 'app_key';
        }

        return trim((string) config('services.helpdesk_mcp.identity_pepper', '')) !== ''
            ? 'environment'
            : 'app_key';
    }

    public function identityAssertionSecretSource(): string
    {
        $settings = $this->databaseSettings();
        if ($settings && $settings->identity_assertion_secret !== null) {
            return trim((string) $settings->identity_assertion_secret) !== '' ? 'database' : 'disabled';
        }

        return trim((string) config('services.helpdesk_mcp.identity_assertion_secret', '')) !== ''
            ? 'environment'
            : 'disabled';
    }

    /**
     * @return array<string, mixed>
     */
    public function formData(): array
    {
        return [
            'tokens' => $this->tokenRecords(),
            'intake_ttl_minutes' => $this->intakeTtlMinutes(),
            'rate_limit_per_minute' => $this->rateLimitPerMinute(),
            'identity_assertion_secret' => '',
            'clear_identity_assertion_secret' => false,
            'identity_assertion_leeway_seconds' => $this->identityAssertionLeewaySeconds(),
            'identity_pepper' => '',
            'reset_identity_pepper' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(array $data): HelpdeskMcpSetting
    {
        $settings = DB::transaction(function () use ($data): HelpdeskMcpSetting {
            $settings = HelpdeskMcpSetting::query()->firstOrNew([
                'id' => HelpdeskMcpSetting::SINGLETON_ID,
            ]);

            $settings->tokens = $this->normalizeTokenRecords($data['tokens'] ?? []);
            $settings->intake_ttl_minutes = max(10, (int) ($data['intake_ttl_minutes'] ?? 30));
            $settings->rate_limit_per_minute = min(600, max(
                60,
                (int) ($data['rate_limit_per_minute'] ?? 300),
            ));
            $settings->identity_assertion_leeway_seconds = max(
                30,
                (int) ($data['identity_assertion_leeway_seconds'] ?? 300),
            );

            if ((bool) ($data['clear_identity_assertion_secret'] ?? false)) {
                $settings->identity_assertion_secret = '';
            } elseif (filled($data['identity_assertion_secret'] ?? null)) {
                $settings->identity_assertion_secret = trim((string) $data['identity_assertion_secret']);
            }

            if ((bool) ($data['reset_identity_pepper'] ?? false)) {
                $settings->identity_pepper = '';
            } elseif (filled($data['identity_pepper'] ?? null)) {
                $settings->identity_pepper = trim((string) $data['identity_pepper']);
            }

            $settings->save();

            return $settings;
        });

        $this->forgetResolvedSettings();

        return $settings->refresh();
    }

    public function forgetResolvedSettings(): void
    {
        $this->settingsResolved = false;
        $this->settings = null;
    }

    private function databaseSettings(): ?HelpdeskMcpSetting
    {
        if ($this->settingsResolved) {
            return $this->settings;
        }

        if (! Schema::hasTable('helpdesk_mcp_settings')) {
            $this->settingsResolved = true;

            return null;
        }

        $settings = HelpdeskMcpSetting::query()->find(
            HelpdeskMcpSetting::SINGLETON_ID,
        );
        $this->settings = $settings;
        $this->settingsResolved = true;

        return $settings;
    }

    /**
     * @return list<array{name: string, token: string, active: bool}>
     */
    private function normalizeTokenRecords(mixed $records): array
    {
        if (! is_array($records)) {
            return [];
        }

        $normalized = [];
        $seen = [];

        foreach ($records as $index => $record) {
            $record = is_array($record) ? $record : ['token' => $record];
            $token = trim((string) ($record['token'] ?? ''));
            if ($token === '' || isset($seen[$token])) {
                continue;
            }

            $seen[$token] = true;
            $normalized[] = [
                'name' => trim((string) ($record['name'] ?? '')) ?: 'Klien '.($index + 1),
                'token' => $token,
                'active' => (bool) ($record['active'] ?? true),
            ];
        }

        return array_values($normalized);
    }
}
