<?php

namespace App\Services\Integrations\External;

use App\Models\BusinessEntity;
use App\Models\ProblemCategory;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Definisi form sistem eksternal (kontrak v1 §3.2) untuk form publik dan
 * panel: diambil lewat server helpdesk (di-cache singkat), dibersihkan, diisi
 * awal dari identitas pelapor, dan dipakai untuk merapikan isian sebelum
 * diteruskan (field tersembunyi lewat visible_if tidak dikirim).
 */
class ExternalFormSchema
{
    public const CACHE_SECONDS = 300;

    public const FIELD_TYPES = [
        'text', 'textarea', 'email', 'phone', 'number', 'date',
        'select', 'radio', 'file', 'files', 'repeater', 'info',
    ];

    public const PREFILL_SOURCES = [
        'reporter.name', 'reporter.phone', 'reporter.email',
        'reporter.unit', 'reporter.job_title', 'reporter.business_entity',
    ];

    private const MAX_DEPTH = 4;

    public function __construct(
        private readonly ExternalTicketService $tickets,
    ) {}

    /**
     * Form yang sudah dibersihkan untuk satu kategori. Hanya respons sukses
     * yang di-cache; kegagalan (404/5xx) selalu dicoba ulang.
     *
     * @return array{key: string, title: string, description: string, downloads: list<array{label: string, url: string}>, fields: list<array<string, mixed>>}
     *
     * @throws ExternalUnavailableException|ExternalNotFoundException|\InvalidArgumentException
     */
    public function form(ProblemCategory $category): array
    {
        $category->loadMissing('unit.integration');
        $integration = $category->unit?->integration;
        if (! $integration || blank($category->external_key)) {
            throw new \InvalidArgumentException('Kategori ini bukan milik unit berintegrasi.');
        }

        $cacheKey = 'external-form:'.$integration->getKey().':'.sha1((string) $category->external_key);

        return Cache::remember(
            $cacheKey,
            self::CACHE_SECONDS,
            fn (): array => $this->sanitize($this->tickets->form($category)),
        );
    }

    /**
     * Bersihkan definisi form mentah: hanya properti kontrak yang
     * diteruskan, key field divalidasi, dan tautan unduhan hanya http(s).
     *
     * @param  array<string, mixed>  $form
     * @return array{key: string, title: string, description: string, downloads: list<array{label: string, url: string}>, fields: list<array<string, mixed>>}
     */
    public function sanitize(array $form): array
    {
        $downloads = [];
        foreach ((array) ($form['downloads'] ?? []) as $download) {
            $url = is_array($download) && is_string($download['url'] ?? null) ? trim($download['url']) : '';
            if (! $this->isHttpUrl($url)) {
                continue;
            }

            $downloads[] = [
                'label' => $this->text($download['label'] ?? null) ?: 'Unduh',
                'url' => $url,
            ];
        }

        return [
            'key' => $this->text($form['key'] ?? null),
            'title' => $this->text($form['title'] ?? null),
            'description' => $this->text($form['description'] ?? null),
            'downloads' => $downloads,
            'fields' => $this->sanitizeFields((array) ($form['fields'] ?? []), 0),
        ];
    }

    /**
     * Terapkan prefill identitas pelapor ke definisi form.
     *
     * $reveal = true (panel, pelapor = pengguna yang login): nilai ditaruh di
     * `default`. $reveal = false (form publik): identitas pelapor tidak
     * dikirim ke browser karena pelapor hanya dikenali dari nomor WhatsApp.
     * Field teks bertanda `auto: true` lalu diisi server saat dikirim; untuk
     * select/radio hanya nilai opsi yang cocok yang dikirim sebagai default.
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    public function withPrefill(array $form, User $reporter, bool $reveal): array
    {
        $form['fields'] = $this->prefillFields((array) ($form['fields'] ?? []), $reporter, $reveal);

        return $form;
    }

    /**
     * Rapikan isian sesuai definisi form sebelum diteruskan: hanya key yang
     * dikenal, repeater menjadi daftar baris, field tersembunyi (visible_if)
     * dibuang, file dipisah ke pohon $files, dan bila $autoPrefill field
     * prefill yang kosong diisi dari identitas pelapor.
     *
     * @param  array<string, mixed>  $form
     * @param  array<string, mixed>  $answers
     * @param  array<string, mixed>  $files
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public function prepareAnswers(array $form, array $answers, array $files, User $reporter, bool $autoPrefill): array
    {
        return $this->prepareLevel(
            (array) ($form['fields'] ?? []),
            $answers,
            $files,
            [],
            $reporter,
            $autoPrefill,
        );
    }

    /**
     * Nilai prefill untuk satu field, atau null bila tidak ada/tidak cocok.
     *
     * @param  array<string, mixed>  $field
     */
    public function prefillValue(array $field, User $reporter): ?string
    {
        $source = $field['prefill'] ?? null;
        if (! is_string($source) || ! in_array($source, self::PREFILL_SOURCES, true)) {
            return null;
        }

        $value = trim(match ($source) {
            'reporter.name' => (string) $reporter->name,
            'reporter.phone' => (string) ($reporter->phone ?? ''),
            'reporter.email' => (string) ($reporter->email ?? ''),
            'reporter.job_title' => (string) ($reporter->job_title ?? ''),
            'reporter.unit' => $reporter->unit_id
                ? (string) Unit::query()->withTrashed()->whereKey($reporter->unit_id)->value('name')
                : '',
            'reporter.business_entity' => $reporter->business_entity_id
                ? (string) BusinessEntity::query()->withTrashed()->whereKey($reporter->business_entity_id)->value('name')
                : '',
        });
        if ($value === '') {
            return null;
        }

        if (! in_array($field['type'] ?? null, ['select', 'radio'], true)) {
            return $value;
        }

        // Select/radio: hanya dipakai bila cocok (tanpa membedakan huruf
        // besar/kecil) dengan nilai atau label salah satu opsi.
        foreach ((array) ($field['options'] ?? []) as $option) {
            if (Str::lower((string) $option['value']) === Str::lower($value)
                || Str::lower((string) $option['label']) === Str::lower($value)) {
                return (string) $option['value'];
            }
        }

        return null;
    }

    /**
     * Apakah field tampil menurut visible_if. $scopes berisi nilai level saat
     * ini lebih dulu, lalu level-level di atasnya.
     *
     * @param  array<string, mixed>  $field
     * @param  list<array<string, mixed>>  $scopes
     */
    public static function isVisible(array $field, array $scopes): bool
    {
        $condition = $field['visible_if'] ?? null;
        if (! is_array($condition) || ! is_string($condition['field'] ?? null)) {
            return true;
        }

        foreach ($scopes as $values) {
            if (array_key_exists($condition['field'], $values)) {
                return self::sameValue($values[$condition['field']], $condition['equals'] ?? null);
            }
        }

        return self::sameValue(null, $condition['equals'] ?? null);
    }

    public static function sameValue(mixed $actual, mixed $expected): bool
    {
        return self::comparable($actual) === self::comparable($expected);
    }

    /**
     * Ubah daftar path error kontrak menjadi path komponen. Dipakai panel
     * untuk memetakan indeks baris repeater ke key item Filament.
     *
     * @param  array<string, mixed>  $form
     * @param  array<string, mixed>  $state  state mentah "answers" (repeater berkunci UUID)
     */
    public static function componentErrorPath(array $form, string $path, array $state): ?string
    {
        $segments = explode('.', $path);
        if (array_shift($segments) !== 'answers') {
            return null;
        }

        $fields = (array) ($form['fields'] ?? []);
        $resolved = ['answers'];
        while ($segments !== []) {
            $key = array_shift($segments);
            $field = collect($fields)->firstWhere('key', $key);
            if (! is_array($field)) {
                return implode('.', $resolved);
            }

            $resolved[] = $key;
            $value = is_array($state) ? ($state[$key] ?? null) : null;
            if (($field['type'] ?? null) !== 'repeater') {
                // Error per file ("answers.dok.0") ditampilkan di field-nya.
                return implode('.', $resolved);
            }

            $index = array_shift($segments);
            if ($index === null || ! ctype_digit($index)) {
                return implode('.', $resolved);
            }

            $rows = is_array($value) ? $value : [];
            $rowKeys = array_keys($rows);
            $rowKey = $rowKeys[(int) $index] ?? $index;
            $resolved[] = (string) $rowKey;
            $state = is_array($rows[$rowKey] ?? null) ? $rows[$rowKey] : [];
            $fields = (array) ($field['fields'] ?? []);
        }

        return implode('.', $resolved);
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $answers
     * @param  array<string, mixed>  $files
     * @param  list<array<string, mixed>>  $parentScopes
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function prepareLevel(
        array $fields,
        array $answers,
        array $files,
        array $parentScopes,
        User $reporter,
        bool $autoPrefill,
    ): array {
        // Nilai level ini dulu (termasuk prefill otomatis) agar visible_if
        // bisa dievaluasi seperti di browser.
        $values = [];
        foreach ($fields as $field) {
            $key = $field['key'];
            $type = $field['type'];
            if (in_array($type, ['info', 'repeater', 'file', 'files'], true)) {
                continue;
            }

            $value = $answers[$key] ?? null;
            if ($value instanceof UploadedFile || is_array($value)) {
                $value = null;
            }

            if ($autoPrefill && ($value === null || trim((string) $value) === '')) {
                $value = $this->prefillValue($field, $reporter) ?? $value;
            }

            $values[$key] = $value;
        }

        $scopes = [$values, ...$parentScopes];
        $cleanAnswers = [];
        $cleanFiles = [];
        foreach ($fields as $field) {
            $key = $field['key'];
            $type = $field['type'];
            if ($type === 'info' || ! self::isVisible($field, $scopes)) {
                continue;
            }

            if ($type === 'file') {
                $file = $this->firstFile($files[$key] ?? ($answers[$key] ?? null));
                if ($file) {
                    $cleanFiles[$key] = $file;
                }

                continue;
            }

            if ($type === 'files') {
                $list = $this->fileList($files[$key] ?? ($answers[$key] ?? null));
                if ($list !== []) {
                    $cleanFiles[$key] = $list;
                }

                continue;
            }

            if ($type === 'repeater') {
                $rowAnswers = is_array($answers[$key] ?? null) ? $answers[$key] : [];
                $rowFiles = is_array($files[$key] ?? null) ? $files[$key] : [];
                $rows = [];
                $rowsFiles = [];
                // Pohon file jarang (hanya baris yang punya unggahan), jadi
                // isian dan file dipasangkan lewat kunci baris aslinya, baru
                // kemudian diberi nomor urut 0..n bersama-sama.
                foreach ($this->rowKeys($rowAnswers, $rowFiles) as $i => $rowKey) {
                    [$rowClean, $rowFileClean] = $this->prepareLevel(
                        (array) ($field['fields'] ?? []),
                        is_array($rowAnswers[$rowKey] ?? null) ? $rowAnswers[$rowKey] : [],
                        is_array($rowFiles[$rowKey] ?? null) ? $rowFiles[$rowKey] : [],
                        $scopes,
                        $reporter,
                        $autoPrefill,
                    );
                    $rows[$i] = $rowClean;
                    if ($rowFileClean !== []) {
                        $rowsFiles[$i] = $rowFileClean;
                    }
                }

                if ($rows !== []) {
                    $cleanAnswers[$key] = $rows;
                }
                if ($rowsFiles !== []) {
                    $cleanFiles[$key] = $rowsFiles;
                }

                continue;
            }

            $cleanAnswers[$key] = $values[$key];
        }

        return [$cleanAnswers, $cleanFiles];
    }

    /**
     * Gabungan kunci baris repeater dari isian dan file, berurutan. Kunci
     * angka (form publik "answers[proses][1]") diurutkan secara numerik;
     * kunci lain (mis. UUID repeater Filament) mengikuti urutan isian.
     *
     * @param  array<int|string, mixed>  $rowAnswers
     * @param  array<int|string, mixed>  $rowFiles
     * @return list<int|string>
     */
    private function rowKeys(array $rowAnswers, array $rowFiles): array
    {
        $keys = array_keys($rowAnswers + $rowFiles);
        if ($keys !== [] && collect($keys)->every(fn (int|string $key): bool => is_int($key))) {
            sort($keys, SORT_NUMERIC);
        }

        return $keys;
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @return list<array<string, mixed>>
     */
    private function prefillFields(array $fields, User $reporter, bool $reveal): array
    {
        foreach ($fields as $index => $field) {
            if (($field['type'] ?? null) === 'repeater') {
                $fields[$index]['fields'] = $this->prefillFields((array) ($field['fields'] ?? []), $reporter, $reveal);

                continue;
            }

            if (! isset($field['prefill'])) {
                continue;
            }

            $value = $this->prefillValue($field, $reporter);
            unset($fields[$index]['prefill']);
            if ($value === null) {
                continue;
            }

            if ($reveal || in_array($field['type'], ['select', 'radio'], true)) {
                $fields[$index]['default'] = $value;

                continue;
            }

            $fields[$index]['auto'] = true;
            unset($fields[$index]['default']);
        }

        return $fields;
    }

    /**
     * @param  array<mixed>  $fields
     * @return list<array<string, mixed>>
     */
    private function sanitizeFields(array $fields, int $depth): array
    {
        $clean = [];
        $seen = [];
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $key = is_string($field['key'] ?? null) ? $field['key'] : '';
            $type = is_string($field['type'] ?? null) ? $field['type'] : '';
            if (! preg_match('/^[a-z0-9_]+$/D', $key) || isset($seen[$key]) || ! in_array($type, self::FIELD_TYPES, true)) {
                continue;
            }

            if ($type === 'repeater' && $depth >= self::MAX_DEPTH) {
                continue;
            }

            $seen[$key] = true;
            $item = [
                'key' => $key,
                'type' => $type,
                'label' => $this->text($field['label'] ?? null) ?: $key,
                'required' => (bool) ($field['required'] ?? false),
            ];

            foreach (['help', 'placeholder'] as $property) {
                $value = $this->text($field[$property] ?? null);
                if ($value !== '') {
                    $item[$property] = $value;
                }
            }

            if (array_key_exists('default', $field) && (is_scalar($field['default']) || is_array($field['default']))) {
                $item['default'] = $field['default'];
            }

            if (is_string($field['prefill'] ?? null) && in_array($field['prefill'], self::PREFILL_SOURCES, true)) {
                $item['prefill'] = $field['prefill'];
            }

            if (in_array($type, ['select', 'radio'], true)) {
                $item['options'] = $this->options($field['options'] ?? []);
            }

            $rules = $this->rules($field['rules'] ?? []);
            if ($rules !== []) {
                $item['rules'] = $rules;
            }

            $condition = $field['visible_if'] ?? null;
            if (is_array($condition) && is_string($condition['field'] ?? null)
                && preg_match('/^[a-z0-9_]+$/D', $condition['field'])
                && (! isset($condition['equals']) || is_scalar($condition['equals']))) {
                $item['visible_if'] = [
                    'field' => $condition['field'],
                    'equals' => $condition['equals'] ?? null,
                ];
            }

            if ($type === 'repeater') {
                $item['fields'] = $this->sanitizeFields((array) ($field['fields'] ?? []), $depth + 1);
                foreach (['min_items', 'max_items'] as $property) {
                    if (is_numeric($field[$property] ?? null) && (int) $field[$property] >= 0) {
                        $item[$property] = (int) $field[$property];
                    }
                }
            }

            $clean[] = $item;
        }

        return $clean;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function options(mixed $options): array
    {
        $clean = [];
        foreach ((array) $options as $option) {
            if (! is_array($option) || ! is_scalar($option['value'] ?? null)) {
                continue;
            }

            $value = (string) $option['value'];
            $clean[] = [
                'value' => $value,
                'label' => $this->text($option['label'] ?? null) ?: $value,
            ];
        }

        return $clean;
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(mixed $rules): array
    {
        if (! is_array($rules)) {
            return [];
        }

        $clean = [];
        foreach (['min', 'max', 'max_kb', 'max_files', 'max_total_kb'] as $rule) {
            if (is_numeric($rules[$rule] ?? null)) {
                $clean[$rule] = $rules[$rule] + 0;
            }
        }

        $minDate = $rules['min_date'] ?? null;
        if (is_string($minDate) && ($minDate === 'today' || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $minDate))) {
            $clean['min_date'] = $minDate;
        }

        if (is_array($rules['accept'] ?? null)) {
            $accept = [];
            foreach ($rules['accept'] as $extension) {
                $extension = is_string($extension) ? Str::lower(ltrim(trim($extension), '.')) : '';
                if (preg_match('/^[a-z0-9]{1,10}$/D', $extension)) {
                    $accept[] = $extension;
                }
            }
            if ($accept !== []) {
                $clean['accept'] = array_values(array_unique($accept));
            }
        }

        return $clean;
    }

    private function firstFile(mixed $value): ?UploadedFile
    {
        if ($value instanceof UploadedFile) {
            return $value;
        }

        return $this->fileList($value)[0] ?? null;
    }

    /**
     * @return list<UploadedFile>
     */
    private function fileList(mixed $value): array
    {
        if ($value instanceof UploadedFile) {
            return [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, fn (mixed $item): bool => $item instanceof UploadedFile));
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function isHttpUrl(string $url): bool
    {
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return in_array(Str::lower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    private static function comparable(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value === null) {
            return '';
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
