<?php

namespace App\Filament\Resources\TicketResource;

use App\Models\ProblemCategory;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use App\Services\Integrations\External\ExternalFormSchema;
use App\Services\Integrations\External\ExternalNotFoundException;
use App\Services\Integrations\External\ExternalTicketService;
use App\Services\Integrations\External\ExternalUnavailableException;
use App\Support\RequestMemo;
use Carbon\CarbonImmutable;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
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

    /** Wizard form sistem tujuan (kontrak §3.2 "Langkah"). */
    public const WIZARD_KEY = 'externalWizard';

    /** Prefix key Group "answers" per seksi wizard. */
    public const WIZARD_GROUP_PREFIX = 'externalAnswers_';

    /** Lama kegagalan form pembuka unit diingat sebelum dicoba lagi (detik). */
    public const UNIT_FAILURE_SECONDS = 30;

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
     * Pertanyaan badan usaha dari form sistem tujuan (ExternalFormSchema::
     * withPrefill `business_entity`): key, label, required, options, default
     * (cocok dengan badan usaha kontrak pengguna yang login). Null bila
     * kategori bukan eksternal atau form tidak punya field badan usaha.
     *
     * @return array{key: string, label: string, required: bool, options: list<array{value: string, label: string}>, default: ?string}|null
     */
    public static function businessEntity(mixed $categoryId): ?array
    {
        $entity = self::definition($categoryId)['form']['business_entity'] ?? null;

        return is_array($entity) ? $entity : null;
    }

    /**
     * Opsi select badan usaha untuk kategori eksternal (nilai => label).
     *
     * @return array<string, string>
     */
    public static function businessEntityOptions(mixed $categoryId): array
    {
        $entity = self::businessEntity($categoryId);

        return $entity === null ? [] : self::options($entity);
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

        $fields = $form['fields'];
        $auto = Forms\Components\Placeholder::make('external_auto')
            ->hiddenLabel()
            ->content(fn (Get $get): ?HtmlString => self::autoNote($fields, self::answersState($get), self::ticketResolver($get)))
            ->visible(fn (Get $get): bool => self::autoItems($fields, self::answersState($get), [], self::ticketResolver($get)) !== [])
            ->columnSpanFull();

        return [...$intro, $auto, ...self::fieldComponents($fields, [])];
    }

    /**
     * Field yang diisi otomatis helpdesk dari identitas pelapor dan
     * benar-benar dikirim (tampil menurut visible_if; di repeater hanya bila
     * ada barisnya): label beserta nilainya (panel boleh menampilkan nilai
     * identitas pengguna yang login), baca-saja. Field badan usaha tidak
     * disebut: dijawab lewat pertanyaan badan usaha helpdesk.
     *
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $state  state "answers" level ini
     * @param  list<array<string, mixed>>  $parentScopes
     * @param  (\Closure(array<string, mixed>): ?string)|null  $ticketValue  nilai field bertanda `ticket`
     * @return array<string, string> label => nilai tampilan
     */
    public static function autoItems(array $fields, array $state = [], array $parentScopes = [], ?\Closure $ticketValue = null): array
    {
        $scopes = [self::levelScope($fields, $state, $ticketValue), ...$parentScopes];
        $items = [];
        foreach ($fields as $field) {
            if (! ExternalFormSchema::isVisible($field, $scopes)) {
                continue;
            }

            if (($field['type'] ?? null) === 'repeater') {
                $rows = $state[$field['key']] ?? [];
                foreach (is_array($rows) ? $rows : [] as $row) {
                    $items += self::autoItems((array) ($field['fields'] ?? []), is_array($row) ? $row : [], $scopes, $ticketValue);
                }

                continue;
            }

            if ($field['auto'] ?? false) {
                $items[(string) $field['label']] ??= (string) ($field['auto_value'] ?? '');
            }
        }

        return $items;
    }

    /**
     * Nilai satu level untuk visible_if: state Filament, dengan field
     * bertanda `ticket` diganti nilai yang akan dikirim server.
     *
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $state
     * @param  (\Closure(array<string, mixed>): ?string)|null  $ticketValue
     * @return array<string, mixed>
     */
    private static function levelScope(array $fields, array $state, ?\Closure $ticketValue): array
    {
        $scope = array_map(fn (mixed $value): mixed => self::scalarState($value), $state);
        foreach ($fields as $field) {
            if ($field['ticket'] ?? false) {
                $scope[$field['key']] = $ticketValue ? $ticketValue($field) : null;
            }
        }

        return $scope;
    }

    /**
     * @return array<string, mixed>
     */
    private static function answersState(Get $get): array
    {
        $state = $get('data.answers', isAbsolute: true);

        return is_array($state) ? $state : [];
    }

    /**
     * Nilai yang akan dikirim server untuk field bertanda `ticket`, dari
     * jawaban pertanyaan badan usaha di form tiket (lihat
     * ExternalFormSchema::prepareAnswers).
     *
     * @return \Closure(array<string, mixed>): ?string
     */
    private static function ticketResolver(Get $get): \Closure
    {
        $entity = self::currentBusinessEntity($get);
        $option = $entity !== null ? self::scalarState($get('data.external_business_entity', isAbsolute: true)) : null;
        $texts = ExternalFormSchema::ticketEntityTexts($entity, $get('data.business_entities_id', isAbsolute: true), $option);

        return fn (array $field): ?string => ExternalFormSchema::ticketValue($field, $texts, $option);
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $state
     * @param  list<array<string, mixed>>|null  $levelFields  semua field level atas bila $fields hanya sebagian (seksi wizard)
     */
    private static function autoNote(array $fields, array $state, \Closure $ticketValue, ?array $levelFields = null): ?HtmlString
    {
        $parentScopes = $levelFields === null ? [] : [self::levelScope($levelFields, $state, $ticketValue)];

        return self::formatAutoNote(self::autoItems($fields, $state, $parentScopes, $ticketValue));
    }

    /**
     * @param  array<string, string>  $items
     */
    private static function formatAutoNote(array $items): ?HtmlString
    {
        if ($items === []) {
            return null;
        }

        $list = collect($items)
            ->map(fn (string $value, string $label): string => '<strong>'.e($label).'</strong>'.($value !== '' ? ': '.e($value) : ''))
            ->implode(' · ');

        return new HtmlString('<span class="text-sm text-gray-600 dark:text-gray-300">Diisi otomatis dari data karyawan: '.$list.'</span>');
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @param  list<array<string, array<string, mixed>>>  $parentLevels  field di level-level atas, berkunci key (terdekat lebih dulu)
     * @param  list<array<string, mixed>>|null  $levelFields  semua field level ini bila $fields hanya sebagian (seksi wizard), untuk visible_if
     * @return array<Component|Forms\Components\Field>
     */
    public static function fieldComponents(array $fields, array $parentLevels, ?array $levelFields = null): array
    {
        $levels = [array_column($levelFields ?? $fields, null, 'key'), ...$parentLevels];
        $components = [];
        foreach ($fields as $field) {
            // Diisi helpdesk saat dikirim: identitas (disebut di catatan
            // "diisi otomatis") dan badan usaha (dijawab lewat pertanyaan
            // badan usaha di form tiket). visible: false = visible_if atas
            // field otomatis yang sudah dihitung server (tidak pernah tampil).
            if (($field['auto'] ?? false) || ($field['ticket'] ?? false) || ($field['visible'] ?? true) === false) {
                continue;
            }

            $component = self::fieldComponent($field, $levels);
            if (! $component) {
                continue;
            }

            $visibility = self::visibility($field, $levels);
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
     * Form pembuka unit berintegrasi yang disusun sebagai wizard (sudah diisi
     * awal dengan identitas pengguna yang login), atau null: unit internal,
     * form tanpa `steps`, atau sistem tujuan gagal dimuat (halaman memakai
     * alur satu halaman yang menampilkan pesannya).
     *
     * Dipanggil belasan kali per render (hidden()/visible() dan langkah
     * wizard), jadi hasilnya diingat per request. Bila form pembuka gagal
     * dimuat, kegagalan itu juga di-cache singkat (UNIT_FAILURE_SECONDS) agar
     * setiap round-trip Livewire tidak menunggu timeout sistem tujuan lagi;
     * halaman memakai alur satu halaman selama itu.
     *
     * @return array{category: ProblemCategory, form: array<string, mixed>}|null
     */
    public static function unitWizard(mixed $unitId, bool $prefill = true): ?array
    {
        if (! is_numeric($unitId)) {
            return null;
        }

        $user = auth()->user();
        $prefill = $prefill && $user instanceof User;

        return RequestMemo::remember(
            'panel-unit-wizard:'.(int) $unitId.':'.($prefill ? 'user-'.$user->getKey() : 'plain'),
            function () use ($unitId, $prefill, $user): ?array {
                $unit = Unit::query()->with('integration')->find((int) $unitId);
                if (! $unit?->isExternal()) {
                    return null;
                }

                $failureKey = 'external-unit-wizard-failed:'.$unit->getKey();
                if (Cache::has($failureKey)) {
                    return null;
                }

                $schemas = app(ExternalFormSchema::class);
                try {
                    $opening = $schemas->unitForm($unit);
                } catch (ExternalNotFoundException|ExternalUnavailableException|InvalidArgumentException) {
                    Cache::put($failureKey, true, self::UNIT_FAILURE_SECONDS);

                    return null;
                }

                if ($opening === null || ! ExternalFormSchema::hasSteps($opening['form'])) {
                    return null;
                }

                if ($prefill) {
                    $opening['form'] = $schemas->withPrefill($opening['form'], $user, reveal: true);
                }

                return $opening;
            },
        );
    }

    /**
     * Ringkasan pertanyaan badan usaha form yang sedang dirender: form
     * kategori eksternal terpilih, atau form pembuka wizard unit sebelum
     * kategori dipilih. Null bila form tidak punya field badan usaha.
     *
     * @return array{key: string, label: string, required: bool, options: list<array{value: string, label: string}>, default: ?string}|null
     */
    public static function currentBusinessEntity(Get $get): ?array
    {
        $categoryId = $get('data.problem_category_id', isAbsolute: true);
        if (self::category($categoryId)) {
            return self::businessEntity($categoryId);
        }

        $entity = self::unitWizard($get('data.unit_id', isAbsolute: true))['form']['business_entity'] ?? null;

        return is_array($entity) ? $entity : null;
    }

    public static function isWizardUnit(mixed $unitId): bool
    {
        return self::unitWizard($unitId, prefill: false) !== null;
    }

    /**
     * Form yang sedang dirender wizard: form kategori terpilih (milik unit
     * itu), atau form pembuka unit sebelum kategori dipilih.
     *
     * @return array{form: ?array<string, mixed>, error: ?string, chosen: bool}
     */
    public static function wizardForm(mixed $unitId, mixed $categoryId): array
    {
        $category = self::category($categoryId);
        if ($category && (string) $category->unit_id === (string) $unitId) {
            ['form' => $form, 'error' => $error] = self::definition($categoryId);
            if ($form !== null) {
                // Form kategori tanpa `steps` memakai langkah form pembuka.
                $form = ExternalFormSchema::asWizard($form, self::unitWizard($unitId)['form'] ?? null);
            }
            if ($form !== null && ExternalFormSchema::hasSteps($form)) {
                return ['form' => $form, 'error' => null, 'chosen' => true];
            }

            if ($error !== null) {
                // Form kategori terpilih gagal dimuat: tampilkan pesannya di
                // langkah kartu kategori, tanpa seksi/langkah milik kategori
                // lain (form pembuka).
                $opening = self::unitWizard($unitId);

                return ['form' => $opening['form'] ?? null, 'error' => $error, 'chosen' => false];
            }
        }

        return ['form' => self::unitWizard($unitId)['form'] ?? null, 'error' => null, 'chosen' => false];
    }

    /**
     * Langkah-langkah wizard (Filament Wizard\Step) sesuai `steps` form:
     * seksi dengan judul + bantuan, pilihan kategori (radio berdeskripsi) di
     * langkah `category_picker`, pertanyaan badan usaha helpdesk di seksi
     * field `ticket.business_entity`, dan ringkasan "terisi otomatis".
     * Langkah tanpa field tampil (mis. observasi untuk kategori lain)
     * disembunyikan; langkah setelah pilihan kategori baru tampil setelah
     * kategori dipilih.
     *
     * @param  \Closure(): array<Component|Forms\Components\Field>  $entityQuestions  pertanyaan badan usaha helpdesk
     * @param  \Closure(Forms\Components\Field, Get, Set, string): void  $onCategoryChanged
     * @return list<Step>
     */
    public static function wizardSteps(mixed $unitId, mixed $categoryId, \Closure $entityQuestions, \Closure $onCategoryChanged): array
    {
        ['form' => $form, 'error' => $error, 'chosen' => $chosen] = self::wizardForm($unitId, $categoryId);
        if ($form === null) {
            return [];
        }

        $allSteps = (array) $form['steps'];
        $fields = (array) ($form['fields'] ?? []);
        $host = self::entityHost($form);
        $hostStepKey = $host[0] ?? null;
        $steps = [];
        $afterPicker = false;
        foreach ($allSteps as $step) {
            $components = [];
            $isPicker = (bool) $step['category_picker'];
            $isHostStep = $hostStepKey === $step['key'];
            $stepFields = array_values(array_filter($fields, fn (array $field): bool => ($field['step'] ?? null) === $step['key']));
            // Seksi langkah kartu kategori baru tampil setelah form kategori dimuat.
            $sectionsHidden = $isPicker && ! $chosen;
            if ($isPicker) {
                $components = self::categoryPicker($unitId, $form, $error, $chosen, $onCategoryChanged);
            }

            $components[] = Forms\Components\Placeholder::make('external_auto_step_'.$step['key'])
                ->hiddenLabel()
                ->content(fn (Get $get): ?HtmlString => self::formatAutoNote(self::stepAutoItems($step, $allSteps, $fields, $hostStepKey, $get)))
                ->visible(fn (Get $get): bool => ! $sectionsHidden && self::stepAutoItems($step, $allSteps, $fields, $hostStepKey, $get) !== [])
                ->columnSpanFull();

            foreach (self::stepSections($step, $fields) as $section) {
                $sectionFields = $section['fields'];
                $isHost = $host === [$step['key'], $section['key']];
                $children = $isHost ? $entityQuestions() : [];
                $children[] = Group::make()
                    ->key(self::WIZARD_GROUP_PREFIX.$step['key'].'_'.$section['key'])
                    ->statePath('answers')
                    ->schema(self::fieldComponents($sectionFields, [], $fields))
                    ->columns(['sm' => 2])
                    ->columnSpanFull();

                $components[] = Section::make($section['title'] !== '' ? $section['title'] : null)
                    ->description($section['help'] ?? null)
                    ->schema($children)
                    ->visible(fn (Get $get): bool => ! $sectionsHidden && ($isHost || self::hasEditableFields($sectionFields, $fields, $get)))
                    ->columns(['sm' => 2]);
            }

            $hiddenUntilChosen = $afterPicker && ! $chosen && ! $isHostStep;
            $steps[] = Step::make($step['title'])
                ->key('external-step-'.$step['key'])
                ->description($step['help'] ?? null)
                ->schema($components)
                ->visible(fn (Get $get): bool => ! $hiddenUntilChosen
                    && ($isPicker || $isHostStep || self::hasEditableFields($stepFields, $fields, $get)));
            $afterPicker = $afterPicker || $isPicker;
        }

        return $steps;
    }

    /**
     * Kunci Step Filament pertama yang memuat salah satu error 422, atau null.
     *
     * @param  array<string, mixed>  $form
     * @param  list<string>  $errorPaths
     */
    public static function wizardErrorStepKey(array $form, array $errorPaths): ?string
    {
        $steps = (array) ($form['steps'] ?? []);
        if ($steps === []) {
            return null;
        }

        $host = self::entityHost($form);
        $fields = collect((array) ($form['fields'] ?? []))->keyBy('key');
        $pickerStep = collect($steps)->first(fn (array $step): bool => (bool) ($step['category_picker'] ?? false))['key'] ?? null;

        foreach ($errorPaths as $path) {
            $path = (string) $path;
            $stepKey = match (true) {
                $path === 'problem_category_id' || $path === 'data.problem_category_id' => $pickerStep,
                in_array($path, ['external_business_entity', 'business_entities_id', 'data.external_business_entity', 'data.business_entities_id'], true) => $host[0] ?? null,
                preg_match('/^(?:data\.)?answers\.([a-z0-9_]+)/', $path, $matches) === 1 => $fields->get($matches[1])['step'] ?? null,
                default => null,
            };

            if (is_string($stepKey) && $stepKey !== '') {
                return 'external-step-'.$stepKey;
            }
        }

        return null;
    }

    /**
     * Seksi satu langkah beserta field-nya; field tanpa seksi yang dikenal
     * masuk seksi tanpa judul di akhir langkah.
     *
     * @param  array<string, mixed>  $step
     * @param  list<array<string, mixed>>  $fields
     * @return list<array{key: string, title: string, help?: string, fields: list<array<string, mixed>>}>
     */
    public static function stepSections(array $step, array $fields): array
    {
        $sections = [];
        foreach ((array) ($step['sections'] ?? []) as $section) {
            $sections[$section['key']] = [...$section, 'fields' => []];
        }
        $loose = ['key' => '_', 'title' => '', 'fields' => []];
        foreach ($fields as $field) {
            if (($field['step'] ?? null) !== $step['key']) {
                continue;
            }

            $sectionKey = $field['section'] ?? null;
            if (is_string($sectionKey) && isset($sections[$sectionKey])) {
                $sections[$sectionKey]['fields'][] = $field;
            } else {
                $loose['fields'][] = $field;
            }
        }

        $sections = array_values($sections);
        if ($loose['fields'] !== []) {
            $sections[] = $loose;
        }

        return $sections;
    }

    /**
     * [langkah, seksi] tempat pertanyaan badan usaha helpdesk dirender:
     * seksi field `ticket.business_entity` level atas pertama, selain itu
     * seksi pertama langkah pertama.
     *
     * @param  array<string, mixed>  $form
     * @return array{0: string, 1: string}|array{}
     */
    public static function entityHost(array $form): array
    {
        $steps = (array) ($form['steps'] ?? []);
        if ($steps === []) {
            return [];
        }

        foreach ((array) ($form['fields'] ?? []) as $field) {
            if (! ($field['ticket'] ?? false)) {
                continue;
            }

            foreach ($steps as $step) {
                if ($step['key'] !== ($field['step'] ?? null)) {
                    continue;
                }

                foreach (self::stepSections($step, (array) $form['fields']) as $section) {
                    if (in_array($field, $section['fields'], true)) {
                        return [$step['key'], $section['key']];
                    }
                }
            }
        }

        $first = self::stepSections($steps[0], (array) ($form['fields'] ?? []))[0] ?? null;

        return $first === null ? [] : [$steps[0]['key'], $first['key']];
    }

    /**
     * Pertahankan isian berkey sama saat kategori wizard berganti (kontrak
     * §3.2): isian level atas lama ditimpakan ke state awal form baru untuk
     * key yang juga ada di form baru.
     *
     * @param  array<string, mixed>  $fresh  state awal form baru
     * @param  array<string, mixed>  $previous  state sebelum kategori berganti
     * @param  list<array<string, mixed>>  $fields  field level atas form baru
     * @return array<string, mixed>
     */
    public static function carryAnswers(array $fresh, array $previous, array $fields): array
    {
        foreach ($fields as $field) {
            $key = $field['key'];
            if (($field['type'] ?? null) === 'info' || ! array_key_exists($key, $previous) || $previous[$key] === null) {
                continue;
            }

            $fresh[$key] = $previous[$key];
        }

        return $fresh;
    }

    /**
     * @param  array<string, mixed>  $form
     * @param  \Closure(Forms\Components\Field, Get, Set, string): void  $onCategoryChanged
     * @return array<Component|Forms\Components\Field>
     */
    private static function categoryPicker(mixed $unitId, array $form, ?string $error, bool $chosen, \Closure $onCategoryChanged): array
    {
        $unit = Unit::query()->with('integration')->find((int) $unitId);
        $cards = $unit ? app(ExternalFormSchema::class)->unitCategories($unit) : [];

        $components = [
            Forms\Components\Radio::make('problem_category_id')
                ->key('external_wizard_problem_category_id')
                ->label('Kategori')
                ->options(collect($cards)->mapWithKeys(fn (array $card): array => [
                    $card['id'] => $card['code'] !== '' && $card['code'] !== $card['name'] ? $card['code'].' — '.$card['name'] : $card['name'],
                ])->all())
                ->descriptions(collect($cards)->mapWithKeys(fn (array $card): array => [$card['id'] => $card['description']])->filter()->all())
                ->in(array_column($cards, 'id'))
                ->required()
                ->live()
                ->afterStateUpdated($onCategoryChanged)
                ->columnSpanFull(),
        ];

        if ($error !== null) {
            $components[] = Forms\Components\Placeholder::make('external_wizard_unavailable')
                ->hiddenLabel()
                ->content(new HtmlString('<span style="color: rgb(220 38 38)">'.e($error).'</span>'))
                ->columnSpanFull();
        } elseif ($chosen) {
            $card = collect($cards)->firstWhere('code', $form['key'] ?? null);
            $name = $card['name'] ?? ($form['title'] ?: 'kategori ini');
            $description = ($card['description'] ?? '') ?: (string) $form['description'];
            $note = ($card['needs_observation'] ?? false) ? ExternalFormSchema::observationNote((string) $unit?->name) : '';
            $links = collect($form['downloads'] ?? [])
                ->map(fn (array $download): string => '<a href="'.e($download['url']).'" target="_blank" rel="noopener noreferrer" class="fi-link fi-color-primary">Unduh: '.e($download['label']).'</a>')
                ->implode(' · ');
            $parts = array_values(array_filter([
                $description !== '' ? nl2br(e($description)) : '',
                $note !== '' ? '<span style="color: rgb(107 114 128)">'.e($note).'</span>' : '',
                $links,
            ]));
            if ($parts !== []) {
                $components[] = Forms\Components\Placeholder::make('external_wizard_info')
                    ->label('Apa itu '.$name.'?')
                    ->content(new HtmlString(implode('<br>', $parts)))
                    ->columnSpanFull();
            }
        }

        return $components;
    }

    /**
     * Apakah salah satu field dapat diisi pelapor (bukan auto, ticket, atau info)
     * dan tampil menurut visible_if.
     *
     * @param  list<array<string, mixed>>  $subset
     * @param  list<array<string, mixed>>  $fields  semua field level atas (untuk visible_if)
     */
    private static function hasEditableFields(array $subset, array $fields, Get $get): bool
    {
        return self::hasEditableFieldsInState($subset, $fields, self::answersState($get), self::ticketResolver($get));
    }

    /**
     * @param  list<array<string, mixed>>  $subset
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $state
     */
    private static function hasEditableFieldsInState(array $subset, array $fields, array $state, \Closure $ticketValue): bool
    {
        $scope = self::levelScope($fields, $state, $ticketValue);
        foreach ($subset as $field) {
            if (($field['type'] ?? null) !== 'info'
                && ! ($field['auto'] ?? false)
                && ! ($field['ticket'] ?? false)
                && ExternalFormSchema::isVisible($field, [$scope])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Field otomatis yang tampil di langkah ini (plus langkah auto-only yang
     * dilewati bila langkah ini adalah pemilik pertanyaan badan usaha).
     *
     * @param  array<string, mixed>  $step
     * @param  list<array<string, mixed>>  $allSteps
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, string>
     */
    private static function stepAutoItems(array $step, array $allSteps, array $fields, ?string $hostStepKey, Get $get): array
    {
        $state = self::answersState($get);
        $ticketValue = self::ticketResolver($get);
        $parentScopes = [self::levelScope($fields, $state, $ticketValue)];
        $stepFields = array_values(array_filter($fields, fn (array $field): bool => ($field['step'] ?? null) === $step['key']));
        $items = self::autoItems($stepFields, $state, $parentScopes, $ticketValue);

        if ($step['key'] === $hostStepKey) {
            foreach ($allSteps as $other) {
                if ($other['key'] === $step['key'] || ($other['category_picker'] ?? false)) {
                    continue;
                }
                $otherFields = array_values(array_filter($fields, fn (array $field): bool => ($field['step'] ?? null) === $other['key']));
                if (! self::hasEditableFieldsInState($otherFields, $fields, $state, $ticketValue)) {
                    $items += self::autoItems($otherFields, $state, $parentScopes, $ticketValue);
                }
            }
        }

        return $items;
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
     * @param  list<array<string, array<string, mixed>>>  $levels
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
                // Repeater satu baris (max_items 1): barisnya tidak bisa ditambah atau dihapus.
                ->addable(! self::isSingleRow($field))
                ->deletable(! self::isSingleRow($field))
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
     * ("key"); level repeater di atasnya lewat "../../key". Field acuan
     * bertanda `ticket` (badan usaha) memakai nilai yang akan dikirim server
     * dari jawaban badan usaha di form tiket. (visible_if atas field otomatis
     * identitas sudah dihitung server.)
     *
     * @param  array<string, mixed>  $field
     * @param  list<array<string, array<string, mixed>>>  $levels
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
        $targetField = null;
        foreach ($levels as $depth => $levelFields) {
            if (array_key_exists($target, $levelFields)) {
                $path = str_repeat('../../', $depth).$target;
                $targetField = $levelFields[$target];

                break;
            }
        }

        if ($path === null) {
            return fn (): bool => ExternalFormSchema::sameValue(null, $equals);
        }

        if ($targetField['ticket'] ?? false) {
            return fn (Get $get): bool => ExternalFormSchema::sameValue(self::ticketResolver($get)($targetField), $equals);
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
     * Repeater berisi tepat satu baris (kontrak §2: `max_items` 1).
     *
     * @param  array<string, mixed>  $field
     */
    private static function isSingleRow(array $field): bool
    {
        return ($field['type'] ?? null) === 'repeater' && isset($field['max_items']) && (int) $field['max_items'] === 1;
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
