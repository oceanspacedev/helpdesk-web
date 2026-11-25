<?php

namespace App\Services\Integrations\External;

use App\Models\BusinessEntity;
use App\Models\ProblemCategory;
use App\Models\Unit;
use App\Models\User;
use App\Support\HelpdeskReporterName;
use App\Support\RequestMemo;
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
        'reporter.name', 'reporter.phone', 'reporter.email', 'reporter.nik',
        'reporter.unit', 'reporter.job_title', 'reporter.job_level',
        'reporter.business_entity', 'ticket.business_entity',
    ];

    /**
     * Sumber yang dijawab lewat pertanyaan helpdesk sendiri ("Badan Usaha
     * yang Dilaporkan"), bukan dari identitas pelapor. Field dengan sumber
     * ini tidak pernah dirender di form eksternal; field itu ditandai
     * `ticket: true` (dan `business_entity: true` untuk select/radio level
     * atas pertama yang opsinya menjadi opsi pertanyaan helpdesk).
     */
    public const TICKET_SOURCES = ['ticket.business_entity'];

    /**
     * Tipe field yang boleh diisi otomatis (file, repeater, info tidak).
     */
    public const AUTO_TYPES = ['text', 'textarea', 'email', 'phone', 'number', 'date', 'select', 'radio'];

    /** Batas judul tiket turunan (kolom tickets.title; sama dengan ExternalTicketService). */
    public const TITLE_MAX_LENGTH = 250;

    private const MAX_DEPTH = 4;

    public function __construct(
        private readonly ExternalTicketService $tickets,
    ) {}

    /**
     * Form yang sudah dibersihkan untuk satu kategori. Hanya respons sukses
     * yang di-cache; kegagalan (404/5xx) dicoba ulang di request berikutnya.
     *
     * @return array{key: string, title: string, description: string, downloads: list<array{label: string, url: string}>, steps: list<array<string, mixed>>, fields: list<array<string, mixed>>}
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

        // Satu render panel memanggil ini dari banyak closure: kegagalan juga
        // diingat selama request itu agar sistem tujuan yang mati/lambat
        // hanya dicoba sekali per request (request berikutnya mencoba ulang).
        return RequestMemo::remember($cacheKey, fn (): array => Cache::remember(
            $cacheKey,
            self::CACHE_SECONDS,
            fn (): array => $this->sanitize($this->tickets->form($category)),
        ));
    }

    /**
     * Bersihkan definisi form mentah: hanya properti kontrak yang
     * diteruskan, key field divalidasi, dan tautan unduhan hanya http(s).
     *
     * Wizard (kontrak §3.2 "Langkah", v1.1): `steps` dibersihkan (key unik,
     * satu `category_picker` saja) dan setiap field level atas diberi `step`
     * yang valid (field tanpa/dengan step tak dikenal masuk langkah terakhir)
     * serta `section` bila seksinya ada di langkah itu. Tanpa `steps`,
     * `steps` = [] dan field tidak membawa step/section.
     *
     * @param  array<string, mixed>  $form
     * @return array{key: string, title: string, description: string, downloads: list<array{label: string, url: string}>, steps: list<array<string, mixed>>, fields: list<array<string, mixed>>}
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

        $steps = $this->sanitizeSteps($form['steps'] ?? []);

        return [
            'key' => $this->text($form['key'] ?? null),
            'title' => $this->text($form['title'] ?? null),
            'description' => $this->text($form['description'] ?? null),
            'downloads' => $downloads,
            'steps' => $steps,
            'fields' => $this->placeFields($this->sanitizeFields((array) ($form['fields'] ?? []), 0), $steps),
        ];
    }

    /**
     * Apakah form disusun sebagai wizard (punya `steps`).
     *
     * @param  array<string, mixed>  $form
     */
    public static function hasSteps(array $form): bool
    {
        return is_array($form['steps'] ?? null) && $form['steps'] !== [];
    }

    /**
     * Apakah salah satu langkah wizard menampilkan pilihan kategori.
     *
     * @param  array<string, mixed>  $form
     */
    public static function hasCategoryPicker(array $form): bool
    {
        foreach ((array) ($form['steps'] ?? []) as $step) {
            if (is_array($step) && ($step['category_picker'] ?? false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Kategori helpdesk unit berintegrasi yang dipetakan ke sistem eksternal,
     * untuk kartu pilihan kategori wizard: id kategori helpdesk, `code`
     * (external_key), `name` (nama kategori helpdesk), dan `description` dari
     * `GET categories`, serta `needs_observation` (kotak "Apa itu" menyebut
     * observasi langsung). Urutan mengikuti sistem eksternal; kategori yang
     * nonaktif / tidak ada di sana tidak ditawarkan. Bila daftar eksternal
     * gagal dimuat, semua kategori terpetakan ditawarkan (urut nama) tanpa
     * deskripsi.
     *
     * @return list<array{id: int, code: string, name: string, description: string, needs_observation: bool}>
     */
    public function unitCategories(Unit $unit): array
    {
        $categories = $this->mappedCategories($unit);
        $external = $this->externalCategories($unit);
        if ($external === null) {
            return array_map(fn (ProblemCategory $category): array => $this->categoryCard($category, '', false), $categories);
        }

        $cards = [];
        foreach ($external as $item) {
            if (! $item['active']) {
                continue;
            }

            foreach ($categories as $category) {
                if ((string) $category->external_key === $item['key']) {
                    $cards[] = $this->categoryCard($category, $item['description'], (bool) ($item['needs_observation'] ?? false));
                }
            }
        }

        return $cards;
    }

    /**
     * Form "pembuka" unit berintegrasi: form kategori terpetakan pertama yang
     * tersedia di sistem eksternal. Dipakai untuk merender langkah-langkah
     * sebelum `category_picker` (field di langkah itu identik di semua
     * kategori, kontrak §3.2) dan menentukan apakah unit memakai wizard.
     *
     * @return array{category: ProblemCategory, form: array<string, mixed>}|null null bila unit tidak punya kategori terpetakan
     *
     * @throws ExternalUnavailableException|ExternalNotFoundException
     */
    public function unitForm(Unit $unit): ?array
    {
        $notFound = null;
        foreach ($this->mappedCategories($unit) as $category) {
            $category->setRelation('unit', $unit);
            try {
                return ['category' => $category, 'form' => $this->form($category)];
            } catch (ExternalNotFoundException $exception) {
                // Kategori nonaktif di sistem eksternal: coba kategori berikutnya.
                $notFound = $exception;
            }
        }

        if ($notFound !== null) {
            throw $notFound;
        }

        return null;
    }

    /**
     * Apakah unit berintegrasi memakai wizard (form pembukanya punya
     * `steps`). False bila form pembuka gagal dimuat.
     */
    public function unitHasSteps(Unit $unit): bool
    {
        try {
            $opening = $this->unitForm($unit);
        } catch (ExternalSystemException|\InvalidArgumentException) {
            return false;
        }

        return $opening !== null && self::hasSteps($opening['form']);
    }

    /**
     * Form kategori yang dirender di dalam wizard unit. Form dengan `steps`
     * dipakai apa adanya. Form tanpa `steps` (mis. kategori yang belum
     * memakai v1.1) memakai langkah form pembuka: field yang key-nya ada di
     * form pembuka masuk langkah/seksi field itu, field lain masuk langkah
     * `category_picker` (tanpa seksi), sehingga kategori itu tetap bisa
     * diajukan lewat wizard.
     *
     * @param  array<string, mixed>  $form
     * @param  array<string, mixed>|null  $opening  form pembuka unit
     * @return array<string, mixed>
     */
    public static function asWizard(array $form, ?array $opening): array
    {
        if (self::hasSteps($form) || $opening === null || ! self::hasSteps($opening)) {
            return $form;
        }

        $steps = array_values((array) $opening['steps']);
        $target = collect($steps)->first(fn (array $step): bool => (bool) ($step['category_picker'] ?? false))
            ?? $steps[array_key_last($steps)];
        $placed = [];
        foreach ((array) ($opening['fields'] ?? []) as $field) {
            if (is_array($field) && isset($field['key'], $field['step'])) {
                $placed[$field['key']] = ['step' => $field['step'], 'section' => $field['section'] ?? null];
            }
        }

        $form['steps'] = $steps;
        $form['fields'] = array_map(function (array $field) use ($placed, $target): array {
            $place = $placed[$field['key'] ?? ''] ?? ['step' => $target['key'], 'section' => null];
            unset($field['section']);
            $field['step'] = $place['step'];
            if ($place['section'] !== null) {
                $field['section'] = $place['section'];
            }

            return $field;
        }, array_values((array) ($form['fields'] ?? [])));

        return $form;
    }

    /**
     * Judul tiket helpdesk untuk form wizard (pelapor tidak lagi ditanya
     * judul), kontrak §5: "{CODE} · {teks}" dengan teks = isian pertama yang
     * tidak kosong dari (1) field teks berkey `judul`/`title` di baris pertama
     * repeater pertama, lalu (2) field teks wajib pertama (level atas, lalu
     * baris pertama repeater pertama) yang bukan isian otomatis; bila tidak
     * ada, nama kategori. Dipotong ke batas kolom judul.
     *
     * @param  array<string, mixed>  $form  form yang sudah dibersihkan
     * @param  array<string, mixed>  $answers  isian yang sudah dirapikan (prepareAnswers)
     */
    public static function deriveTitle(array $form, array $answers, ProblemCategory $category): string
    {
        $fields = (array) ($form['fields'] ?? []);
        $repeater = collect($fields)->first(fn (array $field): bool => ($field['type'] ?? null) === 'repeater');
        $rowFields = is_array($repeater) ? (array) ($repeater['fields'] ?? []) : [];
        $rows = is_array($repeater) ? ($answers[$repeater['key']] ?? null) : null;
        $row = is_array($rows) && is_array(reset($rows)) ? reset($rows) : [];

        $text = self::firstText($rowFields, $row, fn (array $field): bool => in_array($field['key'], ['judul', 'title'], true))
            ?? self::firstText($fields, $answers, fn (array $field): bool => (bool) ($field['required'] ?? false))
            ?? self::firstText($rowFields, $row, fn (array $field): bool => (bool) ($field['required'] ?? false));

        $code = trim((string) $category->external_key);
        $title = $text === null
            ? trim((string) $category->name)
            : ($code !== '' ? $code.' · '.$text : $text);

        return Str::limit(preg_replace('/\s+/u', ' ', $title) ?? $title, self::TITLE_MAX_LENGTH, '');
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $values
     * @param  \Closure(array<string, mixed>): bool  $accept
     */
    private static function firstText(array $fields, array $values, \Closure $accept): ?string
    {
        foreach ($fields as $field) {
            if (($field['type'] ?? null) !== 'text' || ($field['auto'] ?? false) || ($field['ticket'] ?? false)
                || isset($field['prefill']) || ! $accept($field)) {
                continue;
            }

            $value = $values[$field['key']] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    /**
     * @return list<ProblemCategory>
     */
    private function mappedCategories(Unit $unit): array
    {
        return ProblemCategory::query()
            ->where('unit_id', $unit->getKey())
            ->whereNotNull('external_key')
            ->where('external_key', '!=', '')
            ->orderBy('name')
            ->get()
            ->all();
    }

    /**
     * Daftar `GET categories` (di-cache singkat), atau null bila gagal.
     *
     * @return list<array{key: string, name: string, active: bool, description: string, needs_observation: bool}>|null
     */
    private function externalCategories(Unit $unit): ?array
    {
        $integration = $unit->integration;
        if (! $integration) {
            return null;
        }

        $cacheKey = 'external-categories:'.$integration->getKey();
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        return RequestMemo::remember($cacheKey, function () use ($integration, $cacheKey): ?array {
            try {
                $categories = ExternalSystemClient::for($integration)->categories();
            } catch (ExternalSystemException $exception) {
                report($exception);

                return null;
            }

            Cache::put($cacheKey, $categories, self::CACHE_SECONDS);

            return $categories;
        });
    }

    /**
     * @return array{id: int, code: string, name: string, description: string, needs_observation: bool}
     */
    private function categoryCard(ProblemCategory $category, string $description, bool $needsObservation): array
    {
        return [
            'id' => (int) $category->getKey(),
            'code' => (string) $category->external_key,
            'name' => (string) $category->name,
            'description' => $description,
            'needs_observation' => $needsObservation,
        ];
    }

    /**
     * Catatan kotak "Apa itu {kategori}?" untuk kategori dengan observasi
     * langsung (`needs_observation` dari `GET categories`), sama dengan form
     * asli sistem tujuan.
     */
    public static function observationNote(string $unitName): string
    {
        $unitName = trim($unitName);

        return 'Termasuk observasi langsung oleh tim '.($unitName !== '' ? $unitName : 'pelaksana').'.';
    }

    /**
     * @return list<array{key: string, title: string, help?: string, category_picker: bool, sections: list<array{key: string, title: string, help?: string}>}>
     */
    private function sanitizeSteps(mixed $steps): array
    {
        $clean = [];
        $seen = [];
        $picker = false;
        foreach (is_array($steps) ? $steps : [] as $step) {
            $key = is_array($step) && is_string($step['key'] ?? null) ? $step['key'] : '';
            if (! preg_match('/^[a-z0-9_]+$/D', $key) || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $sections = [];
            $seenSections = [];
            foreach ((array) ($step['sections'] ?? []) as $section) {
                $sectionKey = is_array($section) && is_string($section['key'] ?? null) ? $section['key'] : '';
                if (! preg_match('/^[a-z0-9_]+$/D', $sectionKey) || isset($seenSections[$sectionKey])) {
                    continue;
                }

                $seenSections[$sectionKey] = true;
                $item = ['key' => $sectionKey, 'title' => $this->text($section['title'] ?? null)];
                $help = $this->text($section['help'] ?? null);
                if ($help !== '') {
                    $item['help'] = $help;
                }
                $sections[] = $item;
            }

            $item = [
                'key' => $key,
                'title' => $this->text($step['title'] ?? null) ?: $key,
                'category_picker' => ! $picker && (bool) ($step['category_picker'] ?? false),
                'sections' => $sections,
            ];
            $help = $this->text($step['help'] ?? null);
            if ($help !== '') {
                $item['help'] = $help;
            }
            $picker = $picker || $item['category_picker'];
            $clean[] = $item;
        }

        return $clean;
    }

    /**
     * Pasangkan field level atas dengan langkah/seksinya.
     *
     * @param  list<array<string, mixed>>  $fields
     * @param  list<array<string, mixed>>  $steps
     * @return list<array<string, mixed>>
     */
    private function placeFields(array $fields, array $steps): array
    {
        $sections = [];
        foreach ($steps as $step) {
            $sections[$step['key']] = array_column($step['sections'], 'key');
        }
        $last = $steps === [] ? null : $steps[array_key_last($steps)]['key'];

        foreach ($fields as $index => $field) {
            $step = $field['step'] ?? null;
            $section = $field['section'] ?? null;
            unset($fields[$index]['step'], $fields[$index]['section']);
            if ($last === null) {
                continue;
            }

            $step = is_string($step) && isset($sections[$step]) ? $step : $last;
            $fields[$index]['step'] = $step;
            if (is_string($section) && in_array($section, $sections[$step], true)) {
                $fields[$index]['section'] = $section;
            }
        }

        return $fields;
    }

    /**
     * Terapkan prefill identitas pelapor ke definisi form (kontrak §3.2
     * `prefill`). Field yang nilainya diketahui helpdesk (untuk select/radio:
     * cocok dengan salah satu opsi) ditandai `auto: true`: tidak ditampilkan
     * sebagai input, hanya disebut "diisi otomatis", dan diisi server saat
     * dikirim (prepareAnswers). Field tanpa nilai tampil seperti biasa.
     *
     * $reveal = false (form publik): nilainya tidak pernah dikirim ke browser
     * karena pelapor hanya dikenali dari nomor WhatsApp. $reveal = true
     * (panel, pelapor = pengguna yang login): nilai tampilan (label opsi
     * untuk select/radio) ditaruh di `auto_value` untuk ditampilkan baca-saja.
     *
     * Field `ticket.business_entity` dijawab lewat pertanyaan "Badan Usaha
     * yang Dilaporkan" milik helpdesk (lihat markTicketFields). Bila ada
     * select/radio level atas dengan sumber itu, `business_entity` berisi
     * opsinya dan pilihan awal yang cocok dengan badan usaha kontrak pelapor
     * (nilai opsi bukan data identitas); bila tidak ada, null dan helpdesk
     * memakai daftar badan usahanya sendiri.
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    public function withPrefill(array $form, User $reporter, bool $reveal): array
    {
        $form = self::markTicketFields($form);
        $form['fields'] = $this->prefillFields((array) ($form['fields'] ?? []), $reporter, $reveal);

        $field = self::businessEntityField($form);
        $form['business_entity'] = $field === null ? null : [
            'key' => (string) $field['key'],
            'label' => (string) $field['label'],
            'required' => (bool) ($field['required'] ?? false),
            'options' => array_values((array) ($field['options'] ?? [])),
            'default' => self::matchBusinessEntityOption(
                array_values((array) ($field['options'] ?? [])),
                self::entityName($reporter->business_entity_id),
            ),
        ];

        return $form;
    }

    /**
     * Tandai field bersumber `ticket.business_entity`: `ticket: true` untuk
     * tipe yang bisa diisi otomatis (tidak dirender; nilainya diturunkan dari
     * jawaban badan usaha), dan `business_entity: true` untuk select/radio
     * level atas pertama (pertanyaannya diambil alih helpdesk). Penanda
     * `prefill` dibuang.
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    public static function markTicketFields(array $form): array
    {
        $designated = false;
        $form['fields'] = self::markTicketLevel((array) ($form['fields'] ?? []), 0, $designated);

        return $form;
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @return list<array<string, mixed>>
     */
    private static function markTicketLevel(array $fields, int $depth, bool &$designated): array
    {
        foreach ($fields as $index => $field) {
            if (($field['type'] ?? null) === 'repeater') {
                $fields[$index]['fields'] = self::markTicketLevel((array) ($field['fields'] ?? []), $depth + 1, $designated);

                continue;
            }

            if (! in_array($field['prefill'] ?? null, self::TICKET_SOURCES, true)) {
                continue;
            }

            unset($fields[$index]['prefill']);
            if (! in_array($field['type'] ?? null, self::AUTO_TYPES, true)) {
                continue;
            }

            $fields[$index]['ticket'] = true;
            if (! $designated && $depth === 0 && in_array($field['type'], ['select', 'radio'], true)) {
                $fields[$index]['business_entity'] = true;
                $designated = true;
            }
        }

        return $fields;
    }

    /**
     * Field select/radio yang pertanyaannya diambil alih "Badan Usaha yang
     * Dilaporkan" helpdesk, atau null. Menerima form mentah maupun yang
     * sudah ditandai.
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>|null
     */
    public static function businessEntityField(array $form): ?array
    {
        if (! self::isMarked($form)) {
            $form = self::markTicketFields($form);
        }

        foreach ((array) ($form['fields'] ?? []) as $field) {
            if ($field['business_entity'] ?? false) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Opsi field badan usaha yang nilainya persis $value, atau null.
     *
     * @param  array<string, mixed>  $field
     * @return array{value: string, label: string}|null
     */
    public static function businessEntityOption(array $field, mixed $value): ?array
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        foreach ((array) ($field['options'] ?? []) as $option) {
            if ((string) $option['value'] === (string) $value) {
                return ['value' => (string) $option['value'], 'label' => (string) $option['label']];
            }
        }

        return null;
    }

    /**
     * Badan usaha helpdesk yang namanya sama (tanpa membedakan huruf
     * besar/kecil) dengan label atau nilai opsi terpilih, atau null.
     *
     * @param  array{value: string, label: string}|null  $option
     */
    public static function matchingBusinessEntityId(?array $option): ?int
    {
        if ($option === null) {
            return null;
        }

        $names = array_values(array_unique(array_filter([
            Str::lower(trim($option['label'])),
            Str::lower(trim($option['value'])),
        ], fn (string $name): bool => $name !== '')));
        if ($names === []) {
            return null;
        }

        $entities = BusinessEntity::query()->get(['id', 'name']);
        foreach ($names as $name) {
            $entity = $entities->first(fn (BusinessEntity $entity): bool => Str::lower(trim((string) $entity->name)) === $name);
            if ($entity) {
                return (int) $entity->getKey();
            }
        }

        $candidates = array_values(array_unique(array_filter([
            trim($option['label']),
            trim($option['value']),
        ], fn (string $name): bool => $name !== '')));
        $matches = $entities->filter(function (BusinessEntity $entity) use ($candidates): bool {
            foreach ($candidates as $candidate) {
                if (self::entityCoreMatches($candidate, (string) $entity->name)) {
                    return true;
                }
            }

            return false;
        })->values();

        return $matches->count() === 1 ? (int) $matches->first()->getKey() : null;
    }

    /**
     * Cocokkan nama badan usaha helpdesk dengan opsi field badan usaha sistem
     * tujuan: utamakan kecocokan persis (case-insensitive), lalu kecocokan
     * inti nama tanpa awalan badan hukum (PT/CV/UD) atau akronim multi-kata
     * bila hanya ada satu kandidat yang cocok.
     *
     * @param  list<array{value: string, label: string}>  $options
     */
    public static function matchBusinessEntityOption(array $options, ?string $name): ?string
    {
        $needle = Str::lower(trim((string) $name));
        if ($needle === '') {
            return null;
        }

        foreach ($options as $option) {
            if (Str::lower(trim((string) ($option['value'] ?? ''))) === $needle
                || Str::lower(trim((string) ($option['label'] ?? ''))) === $needle) {
                return (string) $option['value'];
            }
        }

        $matched = [];
        foreach ($options as $option) {
            $label = (string) ($option['label'] ?? '');
            $value = (string) ($option['value'] ?? '');
            if (self::entityCoreMatches($needle, $label) || self::entityCoreMatches($needle, $value)) {
                $matched[] = (string) $option['value'];
            }
        }

        $matched = array_values(array_unique($matched));

        return count($matched) === 1 ? $matched[0] : null;
    }

    private static function entityCoreMatches(string $a, string $b): bool
    {
        [$coreA, $initialsA] = self::entityCoreAndInitials($a);
        [$coreB, $initialsB] = self::entityCoreAndInitials($b);
        if ($coreA === '' || $coreB === '') {
            return false;
        }

        if ($coreA === $coreB) {
            return true;
        }

        if (! str_contains($coreA, ' ') && mb_strlen($coreA) >= 2 && $initialsB !== '' && $coreA === $initialsB) {
            return true;
        }

        return ! str_contains($coreB, ' ') && mb_strlen($coreB) >= 2 && $initialsA !== '' && $coreB === $initialsA;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function entityCoreAndInitials(string $name): array
    {
        $normalized = Str::of($name)
            ->lower()
            ->replace(['.', ',', '-', '/'], ' ')
            ->squish()
            ->value();
        if ($normalized === '') {
            return ['', ''];
        }

        $words = explode(' ', $normalized);
        $prefixes = ['pt', 'cv', 'ud', 'yayasan', 'koperasi'];
        while (count($words) > 1 && in_array($words[0], $prefixes, true)) {
            array_shift($words);
        }

        $core = implode(' ', $words);
        $initials = count($words) > 1
            ? implode('', array_map(fn (string $word): string => mb_substr($word, 0, 1), $words))
            : '';

        return [$core, $initials];
    }

    /**
     * Teks sumber untuk field `ticket.business_entity` selain field badan
     * usaha itu sendiri: label lalu nilai opsi terpilih bila form punya field
     * badan usaha, atau nama badan usaha helpdesk terpilih.
     *
     * @param  array<string, mixed>|null  $entityField  field badan usaha (businessEntityField) atau ringkasannya
     * @return list<string>
     */
    public static function ticketEntityTexts(?array $entityField, mixed $businessEntityId, mixed $option): array
    {
        if ($entityField !== null) {
            $chosen = self::businessEntityOption($entityField, $option);

            return $chosen === null ? [] : [$chosen['label'], $chosen['value']];
        }

        $name = self::entityName($businessEntityId);

        return $name === '' ? [] : [$name];
    }

    /**
     * Nilai yang dikirim untuk field bertanda `ticket`: field badan usaha =
     * opsi terpilih; field lain = teks sumber pertama yang cocok dengan
     * field dan lolos aturannya.
     *
     * @param  array<string, mixed>  $field
     * @param  list<string>  $texts
     */
    public static function ticketValue(array $field, array $texts, mixed $option): ?string
    {
        if ($field['business_entity'] ?? false) {
            return self::businessEntityOption($field, $option)['value'] ?? null;
        }

        foreach ($texts as $text) {
            $value = self::autoValue($field, $text);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Kunci pertanyaan badan usaha helpdesk untuk error 422 pada field
     * `ticket.business_entity` level atas (mis. "answers.badan_usaha"):
     * `external_business_entity` bila form punya field badan usaha,
     * `business_entities_id` bila tidak. Null untuk path lain.
     *
     * @param  array<string, mixed>  $form
     */
    public static function businessEntityErrorKey(array $form, string $path): ?string
    {
        $segments = explode('.', $path);
        if (count($segments) < 2 || $segments[0] !== 'answers') {
            return null;
        }

        $form = self::isMarked($form) ? $form : self::markTicketFields($form);
        $field = collect((array) ($form['fields'] ?? []))->firstWhere('key', $segments[1]);
        if (! is_array($field) || ! ($field['ticket'] ?? false)) {
            return null;
        }

        return self::businessEntityField($form) !== null ? 'external_business_entity' : 'business_entities_id';
    }

    /**
     * @param  array<string, mixed>  $form
     */
    private static function isMarked(array $form): bool
    {
        $marked = true;
        array_walk_recursive($form, function (mixed $value, int|string $key) use (&$marked): void {
            if ($key === 'prefill' && in_array($value, self::TICKET_SOURCES, true)) {
                $marked = false;
            }
        });

        return $marked;
    }

    /**
     * Rapikan isian sesuai definisi form sebelum diteruskan: hanya key yang
     * dikenal, repeater menjadi daftar baris, field tersembunyi (visible_if)
     * dibuang, dan file dipisah ke pohon $files. Setiap field yang nilainya
     * diketahui helpdesk selalu diisi server dan menimpa isian browser:
     * identitas pelapor, field badan usaha dari $businessEntityOption (opsi
     * yang dipilih di pertanyaan badan usaha helpdesk), dan field
     * `ticket.business_entity` lain dari opsi itu atau, bila form tidak punya
     * field badan usaha, dari nama badan usaha $businessEntityId.
     * visible_if dievaluasi dengan nilai yang sudah terisi.
     *
     * @param  array<string, mixed>  $form
     * @param  array<string, mixed>  $answers
     * @param  array<string, mixed>  $files
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public function prepareAnswers(
        array $form,
        array $answers,
        array $files,
        User $reporter,
        mixed $businessEntityId = null,
        mixed $businessEntityOption = null,
    ): array {
        $form = self::markTicketFields($form);
        $texts = self::ticketEntityTexts(self::businessEntityField($form), $businessEntityId, $businessEntityOption);

        return $this->prepareLevel(
            (array) ($form['fields'] ?? []),
            $answers,
            $files,
            [],
            $reporter,
            $texts,
            $businessEntityOption,
        );
    }

    /**
     * Jawaban field level atas bersumber reporter.name (nama yang diisi
     * pelapor sendiri bila namanya tidak diketahui helpdesk), atau null.
     *
     * @param  array<string, mixed>  $form  form dari form() (masih membawa `prefill`)
     * @param  array<string, mixed>  $answers
     */
    public static function reporterNameAnswer(array $form, array $answers): ?string
    {
        foreach ((array) ($form['fields'] ?? []) as $field) {
            if (($field['prefill'] ?? null) === 'reporter.name' && is_string($answers[$field['key']] ?? null)
                && trim($answers[$field['key']]) !== '') {
                return trim($answers[$field['key']]);
            }
        }

        return null;
    }

    /**
     * Nilai yang diisi otomatis untuk satu field, atau null bila tidak ada,
     * tidak cocok dengan opsi, atau melanggar aturan field (mis. lebih
     * panjang dari rules.max, format email salah). Field tanpa nilai
     * otomatis tampil sebagai input biasa agar pelapor bisa mengisinya.
     *
     * @param  array<string, mixed>  $field
     */
    public function prefillValue(array $field, User $reporter): ?string
    {
        $source = $this->sourceText($field, $reporter);

        return $source === null ? null : self::autoValue($field, $source);
    }

    /**
     * Nilai sumber prefill apa adanya (belum dicocokkan dengan field).
     *
     * @param  array<string, mixed>  $field
     */
    private function sourceText(array $field, User $reporter): ?string
    {
        $source = $field['prefill'] ?? null;
        // ticket.* dijawab lewat pertanyaan helpdesk (ticketValue).
        if (! is_string($source) || ! in_array($source, self::PREFILL_SOURCES, true)
            || in_array($source, self::TICKET_SOURCES, true)
            || ! in_array($field['type'] ?? null, self::AUTO_TYPES, true)) {
            return null;
        }

        return match ($source) {
            // Nama sementara dari nomor WhatsApp bukan nama pelapor: field nama diisi sendiri.
            'reporter.name' => HelpdeskReporterName::isPlaceholder($reporter) ? '' : (string) $reporter->name,
            'reporter.phone' => (string) ($reporter->phone ?? ''),
            'reporter.email' => (string) ($reporter->email ?? ''),
            'reporter.nik' => (string) ($reporter->identity ?? ''),
            'reporter.job_title' => (string) ($reporter->job_title ?? ''),
            'reporter.job_level' => (string) ($reporter->job_level ?? ''),
            'reporter.unit' => $reporter->unit_id
                ? (string) Unit::query()->withTrashed()->whereKey($reporter->unit_id)->value('name')
                : '',
            'reporter.business_entity' => self::entityName($reporter->business_entity_id),
        };
    }

    /**
     * Nilai otomatis untuk field dari teks sumber: cocok dengan field
     * (matchValue) dan lolos aturan dasarnya (passesRules).
     *
     * @param  array<string, mixed>  $field
     */
    public static function autoValue(array $field, ?string $value): ?string
    {
        $value = self::matchValue($field, $value);

        return $value !== null && self::passesRules($field, $value) ? $value : null;
    }

    /**
     * Aturan dasar kontrak §3.2 yang juga diperiksa browser: panjang teks
     * (rules.min/max), format email, angka beserta batasnya, dan tanggal.
     *
     * @param  array<string, mixed>  $field
     */
    public static function passesRules(array $field, string $value): bool
    {
        $type = $field['type'] ?? null;
        $rules = (array) ($field['rules'] ?? []);
        if (in_array($type, ['select', 'radio'], true)) {
            return true;
        }

        if ($type === 'number') {
            return is_numeric($value)
                && (! is_numeric($rules['min'] ?? null) || $value + 0 >= $rules['min'])
                && (! is_numeric($rules['max'] ?? null) || $value + 0 <= $rules['max']);
        }

        if ($type === 'date') {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if (! $date || $date->format('Y-m-d') !== $value) {
                return false;
            }

            $min = $rules['min_date'] ?? null;
            $min = $min === 'today' ? now()->toDateString() : $min;

            return ! is_string($min) || $value >= $min;
        }

        if ($type === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        $length = mb_strlen($value);

        return (! is_numeric($rules['min'] ?? null) || $length >= $rules['min'])
            && (! is_numeric($rules['max'] ?? null) || $length <= $rules['max']);
    }

    /**
     * Cocokkan nilai sumber dengan field: teks dipakai apa adanya,
     * select/radio hanya bila cocok (tanpa membedakan huruf besar/kecil)
     * dengan nilai atau label salah satu opsi — yang dikembalikan nilai opsi.
     *
     * @param  array<string, mixed>  $field
     */
    public static function matchValue(array $field, ?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (! in_array($field['type'] ?? null, ['select', 'radio'], true)) {
            return $value;
        }

        $needle = Str::lower($value);
        foreach ((array) ($field['options'] ?? []) as $option) {
            if (Str::lower(trim((string) $option['value'])) === $needle
                || Str::lower(trim((string) $option['label'])) === $needle) {
                return (string) $option['value'];
            }
        }

        $group = self::departmentSynonymGroup($needle);
        if ($group === null) {
            return null;
        }

        $matches = [];
        foreach ((array) ($field['options'] ?? []) as $option) {
            $valGroup = self::departmentSynonymGroup(Str::lower(trim((string) ($option['value'] ?? ''))));
            $labelGroup = self::departmentSynonymGroup(Str::lower(trim((string) ($option['label'] ?? ''))));
            if ($valGroup === $group || $labelGroup === $group) {
                $matches[] = (string) $option['value'];
            }
        }

        $matches = array_values(array_unique($matches));

        return count($matches) === 1 ? $matches[0] : null;
    }

    private static function departmentSynonymGroup(string $text): ?string
    {
        $normalized = Str::of($text)
            ->lower()
            ->replace(['&', '/'], ' ')
            ->squish()
            ->value();

        return match ($normalized) {
            'it', 'it support', 'mis', 'management information system' => 'mis',
            'hr', 'hrd', 'hcm', 'human capital', 'human capital management' => 'hcm',
            'fam', 'finance', 'finance accounting', 'finance and accounting', 'accounting' => 'fam',
            'scm', 'supply chain', 'supply chain management' => 'scm',
            'cia', 'audit', 'internal audit' => 'cia',
            'marcomm', 'online', 'online marcomm' => 'marcomm',
            'ds', 'distribution sales' => 'ds',
            'busdev', 'business development' => 'busdev',
            'ga', 'general affair' => 'ga',
            'eo', 'executive office' => 'eo',
            'warehouse', 'gudang' => 'warehouse',
            'retail' => 'retail',
            default => null,
        };
    }

    /**
     * Teks tampilan nilai field: label opsi untuk select/radio.
     *
     * @param  array<string, mixed>  $field
     */
    public static function displayValue(array $field, string $value): string
    {
        foreach ((array) ($field['options'] ?? []) as $option) {
            if ((string) $option['value'] === $value) {
                return (string) $option['label'];
            }
        }

        return $value;
    }

    public static function entityName(mixed $businessEntityId): string
    {
        $id = self::entityId($businessEntityId);
        if ($id === null) {
            return '';
        }

        return (string) BusinessEntity::query()->withTrashed()->whereKey($id)->value('name');
    }

    private static function entityId(mixed $businessEntityId): ?int
    {
        return is_numeric($businessEntityId) && (int) $businessEntityId > 0 ? (int) $businessEntityId : null;
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
        // Hasil visible_if yang sudah dihitung server (withPrefill) karena
        // field acuannya diisi otomatis dan nilainya tidak dikirim.
        if (is_bool($field['visible'] ?? null)) {
            return $field['visible'];
        }

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
        array $ticketTexts,
        mixed $businessEntityOption,
    ): array {
        // Nilai level ini dulu (termasuk isian otomatis helpdesk) agar
        // visible_if dievaluasi dengan nilai yang benar-benar dikirim.
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

            // Nilai yang diketahui helpdesk selalu menang: field itu tidak
            // ditampilkan ke pelapor, jadi isian browser tidak dipercaya.
            $value = ($field['ticket'] ?? false)
                ? self::ticketValue($field, $ticketTexts, $businessEntityOption)
                : ($this->prefillValue($field, $reporter) ?? $value);

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
                        $ticketTexts,
                        $businessEntityOption,
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
     * @param  list<array<string, ?array{value: string}>>  $parentLevels  per level: key => nilai otomatis (null = bukan otomatis)
     * @return list<array<string, mixed>>
     */
    private function prefillFields(array $fields, User $reporter, bool $reveal, array $parentLevels = []): array
    {
        $level = [];
        foreach ($fields as $index => $field) {
            $level[$field['key']] = null;
            if (($field['type'] ?? null) === 'repeater' || ! isset($field['prefill'])) {
                continue;
            }

            $value = $this->prefillValue($field, $reporter);
            unset($fields[$index]['prefill']);
            if ($value === null) {
                // Panel: nilai yang melanggar aturan field tetap jadi isian
                // awal yang bisa diperbaiki pengguna yang login.
                $raw = $reveal ? self::matchValue($field, $this->sourceText($field, $reporter)) : null;
                if ($raw !== null) {
                    $fields[$index]['default'] = $raw;
                }

                continue;
            }

            $fields[$index]['auto'] = true;
            unset($fields[$index]['default']);
            if ($reveal) {
                $fields[$index]['auto_value'] = self::displayValue($field, $value);
            }
            $level[$field['key']] = ['value' => $value];
        }

        $levels = [$level, ...$parentLevels];
        foreach ($fields as $index => $field) {
            if (($field['type'] ?? null) === 'repeater') {
                $fields[$index]['fields'] = $this->prefillFields((array) ($field['fields'] ?? []), $reporter, $reveal, $levels);
            }

            $fields[$index] = self::resolveAutoCondition($fields[$index], $levels);
        }

        return $fields;
    }

    /**
     * visible_if yang mengacu ke field otomatis dihitung di server dan
     * diganti `visible: bool`, karena nilai field itu tidak dikirim ke
     * browser/panel. Hasilnya sama dengan evaluasi prepareAnswers.
     *
     * @param  array<string, mixed>  $field
     * @param  list<array<string, ?array{value: string}>>  $levels
     * @return array<string, mixed>
     */
    private static function resolveAutoCondition(array $field, array $levels): array
    {
        $condition = $field['visible_if'] ?? null;
        if (! is_array($condition) || ! is_string($condition['field'] ?? null)) {
            return $field;
        }

        foreach ($levels as $level) {
            if (! array_key_exists($condition['field'], $level)) {
                continue;
            }

            if ($level[$condition['field']] !== null) {
                unset($field['visible_if']);
                $field['visible'] = self::sameValue($level[$condition['field']]['value'], $condition['equals'] ?? null);
            }

            break;
        }

        return $field;
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

            // Langkah/seksi wizard hanya untuk field level atas (placeFields).
            foreach ($depth === 0 ? ['step', 'section'] : [] as $property) {
                if (is_string($field[$property] ?? null) && preg_match('/^[a-z0-9_]+$/D', $field[$property])) {
                    $item[$property] = $field[$property];
                }
            }

            foreach (['help', 'placeholder'] as $property) {
                $value = $this->text($field[$property] ?? null);
                if ($value !== '') {
                    $item[$property] = $value;
                }
            }

            if (in_array($type, ['text', 'textarea'], true) && is_array($field['suggestions'] ?? null)) {
                $suggestions = [];
                foreach ($field['suggestions'] as $suggestion) {
                    $label = $this->text($suggestion);
                    if ($label !== '' && mb_strlen($label) <= 80 && ! in_array($label, $suggestions, true)) {
                        $suggestions[] = $label;
                    }
                    if (count($suggestions) >= 10) {
                        break;
                    }
                }
                if ($suggestions !== []) {
                    $item['suggestions'] = $suggestions;
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
