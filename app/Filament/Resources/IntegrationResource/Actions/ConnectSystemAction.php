<?php

namespace App\Filament\Resources\IntegrationResource\Actions;

use App\Models\Integration;
use App\Models\Unit;
use App\Services\Integrations\ConnectionCode;
use App\Services\Integrations\External\ExternalSystemException;
use App\Services\Integrations\IntegrationConnector;
use App\Services\Integrations\IntegrationSelfTest;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Enums\Width;
use Illuminate\Support\HtmlString;

/**
 * "Hubungkan sistem": admin menempelkan kode koneksi dari sistem eksternal,
 * memilih unit dan kategori, lalu helpdesk menyiapkan integrasi, unit,
 * kategori, dan webhook sekaligus (IntegrationConnector).
 */
class ConnectSystemAction
{
    public static function make(): Action
    {
        return Action::make('connectSystem')
            ->label('Hubungkan sistem')
            ->icon('heroicon-o-link')
            ->modalHeading('Hubungkan sistem eksternal')
            ->modalDescription('Tempel kode koneksi dari sistem tujuan (mis. Modul: Sistem → Pengaturan Helpdesk → Buat kode koneksi).')
            ->modalWidth(Width::TwoExtraLarge)
            ->modalSubmitActionLabel('Hubungkan')
            ->authorize(fn (): bool => (bool) auth()->user()?->can('create', Integration::class))
            ->steps([
                Step::make('code')
                    ->label('Kode koneksi')
                    ->icon('heroicon-o-key')
                    ->schema([
                        Forms\Components\Textarea::make('code')
                            ->label('Kode koneksi')
                            ->placeholder('hd1_...')
                            ->rows(3)
                            ->required()
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (?string $state, Set $set, Get $get) => static::prefill($state, $set, $get))
                            ->rules([fn (): \Closure => static::codeRule(...)]),
                        Forms\Components\TextInput::make('name')
                            ->label('Nama sistem')
                            ->helperText('Terisi dari kode koneksi. Boleh diganti.')
                            ->required()
                            ->maxLength(100),
                        Forms\Components\Placeholder::make('base_url_preview')
                            ->label('Alamat API')
                            ->content(fn (Get $get): string => static::code($get('code'))?->baseUrl ?? '-')
                            ->visible(fn (Get $get): bool => ConnectionCode::isValid($get('code'))),
                    ]),
                Step::make('unit')
                    ->label('Unit & kategori')
                    ->icon('heroicon-o-briefcase')
                    ->schema([
                        Forms\Components\Select::make('unit_id')
                            ->label('Unit yang memakai sistem ini')
                            ->options(fn (): array => Unit::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->required()
                            ->helperText('Form dan proses tiket unit ini akan dipegang sistem tujuan. Unit lain tidak berubah.')
                            ->createOptionForm([
                                Forms\Components\TextInput::make('name')->label('Nama unit baru')->required()->maxLength(255),
                            ])
                            ->createOptionUsing(fn (array $data): int => Unit::query()->create(['name' => $data['name']])->getKey()),
                        Forms\Components\CheckboxList::make('category_keys')
                            ->label('Kategori yang diimpor')
                            ->helperText('Setiap kategori dibuat sebagai kategori tiket unit ini, lengkap dengan kode eksternalnya. Kategori dengan nama sama dipetakan, tidak digandakan.')
                            ->options(fn (Get $get): array => collect(static::categories($get('code')))
                                ->mapWithKeys(fn (array $category): array => [$category['key'] => $category['name'].' ('.$category['key'].')'])
                                ->all())
                            ->descriptions(fn (Get $get): array => collect(static::categories($get('code')))
                                ->mapWithKeys(fn (array $category): array => [$category['key'] => $category['description']])
                                ->filter()
                                ->all())
                            ->bulkToggleable()
                            ->columns(2)
                            ->required(),
                    ]),
            ])
            ->action(function (array $data, Action $action): void {
                try {
                    $result = app(IntegrationConnector::class)->connect(
                        ConnectionCode::parse($data['code']),
                        (string) $data['name'],
                        (int) $data['unit_id'],
                        array_values((array) $data['category_keys']),
                    );
                } catch (ExternalSystemException|\InvalidArgumentException $exception) {
                    Notification::make()->title('Gagal menghubungkan')->body($exception->getMessage())->danger()->send();
                    $action->halt();

                    return;
                }

                $lines = ["Unit {$result->unit->name} sekarang memakai {$result->integration->name}."];
                if ($result->createdCategories !== []) {
                    $lines[] = count($result->createdCategories).' kategori dibuat: '.implode(', ', $result->createdCategories).'.';
                }
                if ($result->mappedCategories !== []) {
                    $lines[] = count($result->mappedCategories).' kategori lama dipetakan: '.implode(', ', $result->mappedCategories).'.';
                }

                if ($result->webhookError === null) {
                    // Langsung buktikan kedua arah, supaya admin tidak perlu membuat tiket percobaan.
                    $test = app(IntegrationSelfTest::class)->run($result->integration);
                    $lines[] = 'Webhook terdaftar otomatis.';
                    Notification::make()
                        ->title($test->passed() ? 'Terhubung' : 'Terhubung, tetapi ada yang bermasalah')
                        ->body(new HtmlString(e(implode(' ', $lines)).'<br><br><strong>Tes menyeluruh</strong><br>'.nl2br(e($test->summary()))))
                        ->status($test->passed() ? 'success' : 'warning')
                        ->persistent()
                        ->send();

                    return;
                }

                $lines[] = 'Webhook belum terdaftar: '.$result->webhookError
                    .' Atur manual di sistem tujuan dengan URL '.$result->integration->webhookUrl()
                    .' dan rahasia webhook (buat lewat Ubah → Buat acak).';
                Notification::make()->title('Terhubung, webhook perlu diatur manual')->body(implode(' ', $lines))->warning()->persistent()->send();
            });
    }

    private static function code(?string $value): ?ConnectionCode
    {
        try {
            return ConnectionCode::parse($value);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @return list<array{key: string, name: string, active: bool, description: string, needs_observation: bool}>
     */
    private static function categories(?string $value): array
    {
        $code = static::code($value);
        if ($code === null) {
            return [];
        }

        try {
            return app(IntegrationConnector::class)->categories($code);
        } catch (ExternalSystemException) {
            return [];
        }
    }

    /** Isi otomatis hanya field yang masih kosong, supaya pilihan admin tidak tertimpa. */
    private static function prefill(?string $value, Set $set, Get $get): void
    {
        $code = static::code($value);
        if ($code === null) {
            return;
        }

        if (blank($get('name'))) {
            $set('name', $code->name);
        }
        if (blank($get('category_keys'))) {
            $set('category_keys', array_column(static::categories($value), 'key'));
        }
    }

    private static function codeRule(string $attribute, mixed $value, \Closure $fail): void
    {
        try {
            $code = ConnectionCode::parse(is_string($value) ? $value : null);
            app(IntegrationConnector::class)->categories($code);
        } catch (\InvalidArgumentException $exception) {
            $fail($exception->getMessage());
        } catch (ExternalSystemException $exception) {
            $fail('Tidak bisa terhubung: '.$exception->getMessage());
        }
    }
}
