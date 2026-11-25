<?php

namespace App\Filament\Resources\TicketResource;

use App\Models\ProblemCategory;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Integrations\External\ExternalFormSchema;
use App\Services\Integrations\External\ExternalNotFoundException;
use App\Services\Integrations\External\ExternalTicketService;
use App\Services\Integrations\External\ExternalUnavailableException;
use Carbon\CarbonImmutable;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Form dinamis sistem eksternal (kontrak v1 §3.2) sebagai komponen Filament
 * untuk halaman buat tiket di panel, dan tampilan snapshot tiket eksternal di
 * halaman lihat tiket.
 *
 * Pemetaan tipe: text/email/phone/number → TextInput, textarea → Textarea,
 * date → DatePicker, select → Select, radio → Radio, file/files → FileUpload
 * (tidak disimpan di helpdesk; file sementara Livewire diteruskan ke sistem
 * eksternal), repeater → Repeater, info → Placeholder.
 */
final class ExternalTicketForm
{
    public const GROUP_KEY = 'externalAnswers';

    /**
     * Kategori unit berintegrasi untuk id terpilih, atau null.
     */
    public static function category(mixed $categoryId): ?ProblemCategory
    {
        if (! is_numeric($categoryId)) {
            return null;
        }

        $category = ProblemCategory::query()->with('unit.integration')->find((int) $categoryId);

        return $category && $category->unit?->isExternal() ? $category : null;
    }

    public static function isExternalCategory(mixed $categoryId): bool
    {
        return self::category($categoryId) !== null;
    }

    /**
     * Definisi form (sudah diisi awal dengan identitas pengguna yang login)
     * atau pesan kegagalan.
     *
     * @return array{form: ?array<string, mixed>, error: ?string}
     */
    public static function definition(mixed $categoryId): array
    {
        $category = self::category($categoryId);
        if (! $category) {
            return ['form' => null, 'error' => null];
        }

        $schemas = app(ExternalFormSchema::class);
        try {
            $form = $schemas->form($category);
        } catch (ExternalNotFoundException) {
            return ['form' => null, 'error' => 'Form kategori ini tidak tersedia di sistem tujuan. Pilih kategori lain.'];
        } catch (ExternalUnavailableException|InvalidArgumentException $exception) {
            report($exception);

            return ['form' => null, 'error' => ExternalUnavailableException::USER_MESSAGE];
        }

        $user = auth()->user();

        return [
            'form' => $user instanceof User ? $schemas->withPrefill($form, $user, reveal: true) : $form,
            'error' => null,
        ];
    }

    /**
     * Komponen untuk kategori terpilih (state path relatif "answers").
     *
     * @return array<Component|Forms\Components\Field>
     */
    public static function components(mixed $categoryId): array
    {
        ['form' => $form, 'error' => $error] = self::definition($categoryId);
        if ($error !== null) {
            return [
                Forms\Components\Placeholder::make('external_unavailable')
                    ->hiddenLabel()
                    ->content(new HtmlString('<span style="color: rgb(220 38 38)">'.e($error).'</span>')),
            ];
        }

        if ($form === null) {
            return [];
        }

        $intro = [];
        if (filled($form['description']) || $form['downloads'] !== []) {
            $links = collect($form['downloads'])
                ->map(fn (array $download): string => '<a href="'.e($download['url']).'" target="_blank" rel="noopener noreferrer" class="fi-link fi-color-primary">Unduh: '.e($download['label']).'</a>')
                ->implode(' · ');

            $intro[] = Forms\Components\Placeholder::make('external_intro')
                ->label($form['title'] ?: 'Form sistem tujuan')
                ->content(new HtmlString(
                    nl2br(e($form['description'])).($links !== '' ? '<br>'.$links : ''),
                ))
                ->columnSpanFull();
        }

        return [...$intro, ...self::fieldComponents($form['fields'], [])];
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @param  list<list<string>>  $parentLevels  key field di level-level atas (terdekat lebih dulu)
     * @return array<Component|Forms\Components\Field>
     */
    public static function fieldComponents(array $fields, array $parentLevels): array
    {
        $levelKeys = array_column($fields, 'key');
        $components = [];
        foreach ($fields as $field) {
            $component = self::fieldComponent($field, [$levelKeys, ...$parentLevels]);
            if (! $component) {
                continue;
            }

            $visibility = self::visibility($field, [$levelKeys, ...$parentLevels]);
            if ($visibility) {
                $component->visible($visibility);
            }

            $components[] = $component;
        }

        return $components;
    }

    /**
     * Ubah state "answers" Filament menjadi isian kontrak: repeater menjadi
     * daftar baris dan file (TemporaryUploadedFile) dipisah ke pohon $files.
     *
     * @param  array<string, mixed>  $state
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function splitState(array $state): array
    {
        $answers = [];
        $files = [];
        foreach ($state as $key => $value) {
            if ($value instanceof UploadedFile) {
                $files[$key] = $value;

                continue;
            }

            if (is_array($value)) {
                $list = array_values($value);
                if ($list !== [] && collect($list)->every(fn (mixed $item): bool => $item instanceof UploadedFile)) {
                    $files[$key] = $list;

                    continue;
                }

                $rows = [];
                $rowFiles = [];
                foreach ($list as $index => $row) {
                    [$rowAnswers, $rowFileTree] = self::splitState(is_array($row) ? $row : []);
                    $rows[$index] = $rowAnswers;
                    if ($rowFileTree !== []) {
                        $rowFiles[$index] = $rowFileTree;
                    }
                }
                $answers[$key] = $rows;
                if ($rowFiles !== []) {
                    $files[$key] = $rowFiles;
                }

                continue;
            }

            $answers[$key] = $value;
        }

        return [$answers, $files];
    }

    /**
     * Komponen tampilan snapshot tiket eksternal di halaman lihat tiket.
     *
     * @return array<Component>
     */
    public static function snapshotEntries(): array
    {
        return [
            TextEntry::make('external_ref')
                ->label('Referensi sistem tujuan')
                ->state(fn (?Ticket $record): ?string => $record?->external_ref)
                ->url(fn (?Ticket $record): ?string => self::safeUrl(data_get($record?->external_snapshot, 'url')))
                ->openUrlInNewTab()
                ->copyable()
                ->placeholder('-'),
            TextEntry::make('external_status_label')
                ->label('Status di sistem tujuan')
                ->state(fn (?Ticket $record): ?string => $record?->external_status_label)
                ->badge()
                ->color(fn (?Ticket $record): string => match ($record?->external_status_code) {
                    'in_progress' => 'warning',
                    'done' => 'success',
                    'cancelled' => 'danger',
                    default => 'gray',
                })
                ->placeholder('-'),
            TextEntry::make('external_synced_at')
                ->label('Terakhir disinkronkan')
                ->state(fn (?Ticket $record): ?string => $record?->external_synced_at?->diffForHumans())
                ->placeholder('-'),
            TextEntry::make('external_summary')
                ->label('Ringkasan')
                ->state(fn (?Ticket $record): array => self::rows($record, 'summary', fn (array $row): string => self::join([
                    self::text($row['label'] ?? null),
                    self::text($row['value'] ?? null),
                ], ': ')))
                ->listWithLineBreaks()
                ->placeholder('-')
                ->columnSpanFull(),
            TextEntry::make('external_items')
                ->label('Item')
                ->state(fn (?Ticket $record): array => self::rows($record, 'items', fn (array $row): string => self::join([
                    self::text($row['title'] ?? null),
                    self::text(data_get($row, 'status.label') ?? data_get($row, 'status.code')),
                    filled(self::text($row['pic'] ?? null)) ? 'PIC: '.self::text($row['pic']) : '',
                ], ' · ')))
                ->listWithLineBreaks()
                ->bulleted()
                ->placeholder('-')
                ->columnSpanFull(),
            TextEntry::make('external_timeline')
                ->label('Riwayat')
                ->state(fn (?Ticket $record): array => self::rows($record, 'timeline', fn (array $row): string => self::join([
                    self::time($row['at'] ?? null),
                    self::text($row['label'] ?? null),
                    self::text($row['note'] ?? null),
                ], ' · ')))
                ->listWithLineBreaks()
                ->bulleted()
                ->placeholder('-')
                ->columnSpanFull(),
            TextEntry::make('external_comments')
                ->label('Komentar dari sistem tujuan')
                ->state(fn (?Ticket $record): array => self::rows($record, 'comments', fn (array $row): string => self::join([
                    self::join([self::text($row['author'] ?? null), self::time($row['at'] ?? null)], ' · '),
                    self::text($row['body'] ?? null),
                ], ': ')))
                ->listWithLineBreaks()
                ->bulleted()
                ->placeholder('-')
                ->columnSpanFull(),
            TextEntry::make('external_files')
                ->label('File')
                ->state(function (?Ticket $record): ?HtmlString {
                    $links = self::rows($record, 'files', function (array $row): string {
                        $name = self::text($row['name'] ?? null) ?: 'File';
                        $url = self::safeUrl($row['url'] ?? null);

                        return $url
                            ? '<a href="'.e($url).'" target="_blank" rel="noopener noreferrer" class="fi-link fi-color-primary">'.e($name).'</a>'
                            : e($name);
                    });

                    return $links === [] ? null : new HtmlString(implode('<br>', $links));
                })
                ->html()
                ->placeholder('-')
                ->columnSpanFull(),
        ];
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  list<list<string>>  $levels
     */
    private static function fieldComponent(array $field, array $levels): Component|Forms\Components\Field|null
    {
        $key = (string) $field['key'];
        $label = (string) $field['label'];
        $rules = (array) ($field['rules'] ?? []);
        $required = (bool) ($field['required'] ?? false);

        $component = match ($field['type']) {
            'text', 'email', 'phone', 'number' => self::textInput($field, $rules),
            'textarea' => Forms\Components\Textarea::make($key)
                ->rows(4)
                ->minLength(isset($rules['min']) ? (int) $rules['min'] : null)
                ->maxLength(isset($rules['max']) ? (int) $rules['max'] : null),
            'date' => Forms\Components\DatePicker::make($key)
                ->native(false)
                ->displayFormat('d/m/Y')
                ->minDate(self::minDate($rules)),
            'select' => Forms\Components\Select::make($key)
                ->options(self::options($field))
                ->searchable(count($field['options'] ?? []) > 5),
            'radio' => Forms\Components\Radio::make($key)
                ->options(self::options($field)),
            'file', 'files' => self::fileUpload($field, $rules),
            'repeater' => Forms\Components\Repeater::make($key)
                ->schema(self::fieldComponents((array) ($field['fields'] ?? []), $levels))
                ->minItems(isset($field['min_items']) ? (int) $field['min_items'] : null)
                ->maxItems(isset($field['max_items']) ? (int) $field['max_items'] : null)
                ->defaultItems(min(
                    max((int) ($field['min_items'] ?? 0), 1),
                    isset($field['max_items']) ? (int) $field['max_items'] : PHP_INT_MAX,
                ))
                ->addActionLabel('Tambah '.$label)
                ->reorderable(false)
                ->columnSpanFull(),
            'info' => Forms\Components\Placeholder::make('info_'.$key)
                ->content((string) ($field['help'] ?? ''))
                ->columnSpanFull(),
            default => null,
        };

        if (! $component) {
            return null;
        }

        $component->label($label);
        if ($field['type'] === 'info') {
            return $component;
        }

        $component->required($required);

        $helper = collect([$field['help'] ?? null, self::rulesHint($field)])->filter()->implode(' · ');
        if ($helper !== '') {
            $component->helperText($helper);
        }

        if (filled($field['placeholder'] ?? null) && method_exists($component, 'placeholder')) {
            $component->placeholder((string) $field['placeholder']);
        }

        if (array_key_exists('default', $field) && ! in_array($field['type'], ['file', 'files', 'repeater'], true)) {
            $component->default(is_scalar($field['default']) ? (string) $field['default'] : null);
        }

        return $component;
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $rules
     */
    private static function textInput(array $field, array $rules): Forms\Components\TextInput
    {
        $input = Forms\Components\TextInput::make((string) $field['key']);

        return match ($field['type']) {
            'email' => $input->email(),
            'phone' => $input->tel(),
            'number' => $input->numeric()
                ->minValue(isset($rules['min']) ? $rules['min'] : null)
                ->maxValue(isset($rules['max']) ? $rules['max'] : null),
            default => $input
                ->minLength(isset($rules['min']) ? (int) $rules['min'] : null)
                ->maxLength(isset($rules['max']) ? (int) $rules['max'] : null),
        };
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $rules
     */
    private static function fileUpload(array $field, array $rules): Forms\Components\FileUpload
    {
        $upload = Forms\Components\FileUpload::make((string) $field['key'])
            // File tidak disimpan di helpdesk; diteruskan ke sistem eksternal.
            ->storeFiles(false)
            ->previewable(false)
            ->columnSpanFull();

        // Batas bawaan sama dengan form publik; aturan form eksternal hanya
        // boleh memperketat, tidak melonggarkan.
        if ($field['type'] === 'files') {
            $upload->multiple();
            $upload->maxFiles(isset($rules['max_files'])
                ? max(1, min((int) $rules['max_files'], ExternalTicketService::MAX_FILES))
                : ExternalTicketService::MAX_FILES);
        }

        $upload->maxSize(isset($rules['max_kb'])
            ? max(1, min((int) $rules['max_kb'], ExternalTicketService::MAX_FILE_KB))
            : ExternalTicketService::MAX_FILE_KB);

        if (! empty($rules['accept'])) {
            $upload->rule('extensions:'.implode(',', $rules['accept']));
        }

        // max_total_kb per field hanya ditampilkan sebagai petunjuk: aturan
        // FileUpload Filament berlaku per file. Total seluruh lampiran dibatasi
        // saat submit (CreateTicket), dan sistem tujuan tetap memvalidasi ulang.

        return $upload;
    }

    /**
     * visible_if → closure Filament. Field di level yang sama dibaca relatif
     * ("key"); level repeater di atasnya lewat "../../key".
     *
     * @param  array<string, mixed>  $field
     * @param  list<list<string>>  $levels
     */
    private static function visibility(array $field, array $levels): ?\Closure
    {
        $condition = $field['visible_if'] ?? null;
        if (! is_array($condition)) {
            return null;
        }

        $target = (string) $condition['field'];
        $equals = $condition['equals'] ?? null;
        $path = null;
        foreach ($levels as $depth => $keys) {
            if (in_array($target, $keys, true)) {
                $path = str_repeat('../../', $depth).$target;

                break;
            }
        }

        if ($path === null) {
            return fn (): bool => ExternalFormSchema::sameValue(null, $equals);
        }

        return fn (Get $get): bool => ExternalFormSchema::sameValue(self::scalarState($get($path)), $equals);
    }

    private static function scalarState(mixed $state): mixed
    {
        if ($state instanceof \BackedEnum) {
            return $state->value;
        }

        return is_scalar($state) || $state === null ? $state : null;
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<string, string>
     */
    private static function options(array $field): array
    {
        return collect((array) ($field['options'] ?? []))
            ->mapWithKeys(fn (array $option): array => [(string) $option['value'] => (string) $option['label']])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    private static function minDate(array $rules): ?string
    {
        $minDate = $rules['min_date'] ?? null;
        if ($minDate === 'today') {
            return now()->toDateString();
        }

        return is_string($minDate) ? $minDate : null;
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private static function rulesHint(array $field): string
    {
        $rules = (array) ($field['rules'] ?? []);
        $parts = [];
        if (in_array($field['type'], ['file', 'files'], true)) {
            if (! empty($rules['accept'])) {
                $parts[] = 'Format: '.implode(', ', $rules['accept']);
            }
            if (isset($rules['max_kb'])) {
                $parts[] = 'maks. '.$rules['max_kb'].' KB per file';
            }
            if (isset($rules['max_files']) && $field['type'] === 'files') {
                $parts[] = 'maks. '.$rules['max_files'].' file';
            }
            if (isset($rules['max_total_kb'])) {
                $parts[] = 'total maks. '.$rules['max_total_kb'].' KB';
            }
        }

        return implode(' · ', $parts);
    }

    /**
     * @param  callable(array<string, mixed>): string  $format
     * @return list<string>
     */
    private static function rows(?Ticket $record, string $key, callable $format): array
    {
        $rows = data_get($record?->external_snapshot, $key);
        if (! is_array($rows)) {
            return [];
        }

        return collect($rows)
            ->filter(fn (mixed $row): bool => is_array($row))
            ->map(fn (array $row): string => $format($row))
            ->filter(fn (string $line): bool => $line !== '')
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $parts
     */
    private static function join(array $parts, string $glue): string
    {
        return implode($glue, array_values(array_filter($parts, fn (string $part): bool => $part !== '')));
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? Str::limit(trim((string) $value), 2000) : '';
    }

    private static function time(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return '';
        }

        try {
            return CarbonImmutable::parse($value)->timezone(config('app.timezone'))->format('d M Y H:i');
        } catch (Throwable) {
            return '';
        }
    }

    private static function safeUrl(mixed $url): ?string
    {
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return in_array(Str::lower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true) ? $url : null;
    }
}
