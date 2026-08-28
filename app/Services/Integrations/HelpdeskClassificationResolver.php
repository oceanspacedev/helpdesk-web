<?php

namespace App\Services\Integrations;

use App\Models\BusinessEntity;
use App\Models\Priority;
use App\Models\ProblemCategory;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

class HelpdeskClassificationResolver
{
    public const LIST_DISPLAY_LIMIT = 20;

    public function __construct(
        private HelpdeskFormOptionsService $formOptionsService,
    ) {}

    /**
     * @return array{
     *     issues: array<int, array{field: string, reason: string, provided_value: ?string}>,
     *     businessEntity: ?BusinessEntity,
     *     unit: ?Unit,
     *     category: ?ProblemCategory,
     *     priority: ?Priority
     * }
     */
    public function evaluate(array $ticketData, ?User $owner = null): array
    {
        $issues = [];

        $entityProvided = $this->providedFieldValue(
            $ticketData,
            ['business_entities_id', 'business_entity_id'],
            ['business_entity', 'business_entity_name']
        );
        $businessEntity = $this->resolveBusinessEntity($ticketData);
        if (! $businessEntity) {
            $issues[] = $this->fieldIssue('business_entities_id', $entityProvided);
        }

        $unitProvided = $this->providedFieldValue($ticketData, ['unit_id'], ['unit', 'unit_name']);
        $unit = $this->resolveUnit($ticketData, $owner);
        if (! $unit) {
            $issues[] = $this->fieldIssue('unit_id', $unitProvided);
        }

        $categoryProvided = $this->providedFieldValue(
            $ticketData,
            ['problem_category_id', 'category_id'],
            ['problem_category', 'category', 'category_name']
        );
        $category = $unit ? $this->resolveProblemCategory($ticketData, $unit) : null;
        if (! $category) {
            $issues[] = $this->fieldIssue('problem_category_id', $categoryProvided);
        }

        $priorityProvided = $this->providedFieldValue($ticketData, ['priority_id'], ['priority']);
        $priority = $this->resolvePriority($ticketData);
        if (! $priority) {
            $issues[] = $this->fieldIssue('priority_id', $priorityProvided);
        }

        return [
            'issues' => $issues,
            'businessEntity' => $businessEntity,
            'unit' => $unit,
            'category' => $category,
            'priority' => $priority,
        ];
    }

    /**
     * Cocokkan teks laporan ke master data. Hanya mengisi field jika
     * tepat satu nama master data ketemu. Nol atau lebih dari satu = jangan tebak.
     *
     * @param  array<string, mixed>  $ticketContext
     * @return array{
     *     filled: array<string, mixed>,
     *     missing_fields: list<string>,
     *     matched: list<array{field: string, id: int, name: string}>,
     *     ambiguous: list<array{field: string, candidates: list<string>}>,
     *     form_options: array<string, mixed>,
     *     next_field: ?string,
     *     next_question: string,
     *     suggested_title: string,
     *     suggested_description: string
     * }
     */
    public function prefillFromMessage(string $message, array $ticketContext = [], ?User $owner = null): array
    {
        $message = $this->text($message);
        $filled = $ticketContext;
        $matched = [];
        $ambiguous = [];

        $suggestedTitle = $this->text($filled['title'] ?? '') ?: $this->titleFromMessage($message);
        $suggestedDescription = $this->text($filled['description'] ?? '') ?: $message;
        if ($suggestedTitle !== '') {
            $filled['title'] = $suggestedTitle;
        }
        if ($suggestedDescription !== '') {
            $filled['description'] = $suggestedDescription;
        }

        foreach ([
            'business_entities_id' => $this->matchUniqueMasterRecord($message, BusinessEntity::query()->orderBy('name')->get(['id', 'name'])),
            'unit_id' => $this->matchUniqueMasterRecord($message, Unit::query()->orderBy('name')->get(['id', 'name'])),
            'priority_id' => $this->matchUniquePriority($message),
        ] as $field => $result) {
            if (($result['status'] ?? '') === 'matched' && isset($result['record'])) {
                $record = $result['record'];
                $filled = array_merge($filled, $this->resolvedPatch($field, $record));
                $matched[] = [
                    'field' => $field,
                    'id' => (int) $record->id,
                    'name' => (string) $record->name,
                ];
            } elseif (($result['status'] ?? '') === 'ambiguous') {
                $ambiguous[] = [
                    'field' => $field,
                    'candidates' => $result['candidates'],
                ];
            }
        }

        $unit = $this->resolveUnit($filled, $owner);
        if ($unit) {
            $categoryResult = $this->matchUniqueMasterRecord(
                $message,
                ProblemCategory::query()->where('unit_id', $unit->id)->orderBy('name')->get(['id', 'name', 'unit_id']),
            );
            if (($categoryResult['status'] ?? '') === 'matched' && isset($categoryResult['record'])) {
                $record = $categoryResult['record'];
                $filled = array_merge($filled, $this->resolvedPatch('problem_category_id', $record));
                $matched[] = [
                    'field' => 'problem_category_id',
                    'id' => (int) $record->id,
                    'name' => (string) $record->name,
                ];
            } elseif (($categoryResult['status'] ?? '') === 'ambiguous') {
                $ambiguous[] = [
                    'field' => 'problem_category_id',
                    'candidates' => $categoryResult['candidates'],
                ];
            }
        }

        $formOptions = $this->formOptionsService->formOptions($unit?->id);
        $nextField = $this->nextMissingCreateField($filled, $owner);

        return [
            'filled' => $filled,
            'missing_fields' => $this->missingCreateFields($filled, $owner),
            'matched' => $matched,
            'ambiguous' => $ambiguous,
            'form_options' => $formOptions,
            'next_field' => $nextField,
            'next_question' => $this->nextQuestionForField((string) ($nextField ?? 'consent_to_create')),
            'suggested_title' => $suggestedTitle,
            'suggested_description' => $suggestedDescription,
        ];
    }

    public function validateField(
        string $field,
        mixed $value,
        array $ticketContext = [],
        ?User $owner = null,
    ): array {
        $field = $this->normalizeField($field);
        $providedValue = $this->text($value);
        $unitHint = $this->resolveUnit($ticketContext, $owner);
        $formOptions = $this->formOptionsService->formOptions($unitHint?->id);
        $listChoice = $this->resolveNumberedListChoice($field, $providedValue, $formOptions);
        if ($listChoice !== null) {
            $ticketData = array_merge($ticketContext, $this->resolvedPatch($field, $listChoice));
        } elseif (ctype_digit($providedValue)) {
            $ticketData = $ticketContext;
        } else {
            $ticketData = array_merge($ticketContext, $this->patchForField($field, $providedValue));
        }
        $unit = $this->resolveUnit($ticketData, $owner);
        $formOptions = $this->formOptionsService->formOptions($unit?->id);

        if ($providedValue === '') {
            return [
                'ok' => false,
                'field' => $field,
                'reason' => 'missing',
                'provided_value' => null,
                'resolved' => null,
                'message' => $this->buildFieldValidationMessage($this->fieldIssue($field, null), $formOptions),
                'form_options' => $formOptions,
                'next_field' => $field,
            ];
        }

        $resolved = match ($field) {
            'business_entities_id' => $this->resolveBusinessEntity($ticketData),
            'unit_id' => $this->resolveUnit($ticketData, $owner),
            'problem_category_id' => $unit ? $this->resolveProblemCategory($ticketData, $unit) : null,
            'priority_id' => $this->resolvePriority($ticketData),
            default => null,
        };

        if (! $resolved) {
            return [
                'ok' => false,
                'field' => $field,
                'reason' => 'not_found',
                'provided_value' => $providedValue,
                'resolved' => null,
                'message' => $this->buildFieldValidationMessage($this->fieldIssue($field, $providedValue), $formOptions),
                'form_options' => $formOptions,
                'next_field' => $field,
            ];
        }

        $patch = $this->resolvedPatch($field, $resolved);
        $mergedTicketData = array_merge($ticketContext, $patch);
        $nextField = $this->nextMissingField($mergedTicketData, $owner);

        return [
            'ok' => true,
            'field' => $field,
            'reason' => 'validated',
            'provided_value' => $providedValue,
            'resolved' => [
                'id' => (int) $resolved->id,
                'name' => (string) $resolved->name,
                'ticket_data_patch' => $patch,
            ],
            'message' => $this->buildValidatedMessage($field, (string) $resolved->name, $nextField, $formOptions, $owner, $mergedTicketData),
            'form_options' => $formOptions,
            'next_field' => $nextField,
        ];
    }

    public function buildFieldValidationMessage(array $issue, array $formOptions): string
    {
        $label = $this->fieldLabel($issue['field']);
        $optionsBlock = $this->formatFormOptionsList($issue['field'], $formOptions);
        $provided = $this->text($issue['provided_value'] ?? '');

        if (($issue['reason'] ?? '') === 'not_found' && $provided !== '') {
            $intro = ctype_digit($provided)
                ? "{$label} dengan ID {$provided} tidak ditemukan di Helpdesk. Pilih dari daftar ini:"
                : "{$label} \"{$provided}\" tidak ditemukan di Helpdesk. Pilih dari daftar ini:";
        } else {
            $intro = $this->fieldQuestion($issue['field']);
        }

        if ($optionsBlock === '') {
            return $intro;
        }

        return "{$intro}\n\nPilih salah satu:\n{$optionsBlock}\n\nBalas nomor atau tulis nama persis seperti di daftar.";
    }

    public function nextQuestionForField(string $field): string
    {
        return match ($this->normalizeField($field)) {
            'phone' => 'Nomor WhatsApp pelapor? Format 08... atau 62...',
            'business_entities_id' => 'Entitas bisnis/cabang apa? Contoh: Complete Selular.',
            'unit_id' => 'Unit kerja yang menangani? Contoh: IT.',
            'problem_category_id' => 'Kategori masalah sesuai form Helpdesk? Contoh: Akses Akun.',
            'title' => 'Judul tiket? Ringkas, seperti di form web Helpdesk.',
            'description' => 'Jelaskan kendalanya. Apa yang terjadi, sejak kapan, dan apa yang sudah dicoba.',
            'priority_id' => 'Prioritas tiket? Pilih Low, Medium, High, Critical, atau Enhancement.',
            'supporting_attachments' => 'Ada lampiran? Kirim file atau ketik lewati.',
            'consent_to_create' => 'Buat tiket sekarang? Ketik ya untuk konfirmasi.',
            default => 'Lengkapi data tiket sesuai form Helpdesk web.',
        };
    }

    public function resolveBusinessEntity(array $ticketData): ?BusinessEntity
    {
        $id = (int) ($ticketData['business_entities_id'] ?? $ticketData['business_entity_id'] ?? 0);
        if ($id > 0 && $entity = BusinessEntity::find($id)) {
            return $entity;
        }

        $name = $this->text($ticketData['business_entity'] ?? $ticketData['business_entity_name'] ?? '');
        if ($name === '') {
            return null;
        }

        return BusinessEntity::query()
            ->whereRaw('LOWER(name) = ?', [Str::lower($name)])
            ->first();
    }

    public function resolveUnit(array $ticketData, ?User $owner = null): ?Unit
    {
        $id = (int) ($ticketData['unit_id'] ?? 0);
        if ($id > 0 && $unit = Unit::find($id)) {
            return $unit;
        }

        $unitName = $this->text($ticketData['unit'] ?? $ticketData['unit_name'] ?? '');
        if ($unitName !== '') {
            return Unit::query()->whereRaw('LOWER(name) = ?', [Str::lower($unitName)])->first();
        }

        if ($owner) {
            try {
                $ownerUnits = $owner->units()->get();
                if ($ownerUnits->count() === 1) {
                    return $ownerUnits->first();
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return null;
    }

    public function resolveProblemCategory(array $ticketData, Unit $unit): ?ProblemCategory
    {
        $id = (int) ($ticketData['problem_category_id'] ?? $ticketData['category_id'] ?? 0);
        if ($id > 0) {
            $category = ProblemCategory::find($id);
            if ($category && (int) $category->unit_id === (int) $unit->id) {
                return $category;
            }
        }

        $name = $this->text($ticketData['problem_category'] ?? $ticketData['category'] ?? $ticketData['category_name'] ?? '');
        if ($name === '') {
            return null;
        }

        return ProblemCategory::query()
            ->where('unit_id', $unit->id)
            ->whereRaw('LOWER(name) = ?', [Str::lower($name)])
            ->first();
    }

    public function resolvePriority(array $ticketData): ?Priority
    {
        $id = (int) ($ticketData['priority_id'] ?? 0);
        if ($id > 0 && $priority = Priority::find($id)) {
            return $priority;
        }

        $priorityName = $this->text($ticketData['priority'] ?? '');
        if ($priorityName === '') {
            return null;
        }

        $normalized = Str::lower($priorityName);
        $aliases = [
            'critical' => Priority::CRITICAL,
            'kritis' => Priority::CRITICAL,
            'urgent' => Priority::CRITICAL,
            'darurat' => Priority::CRITICAL,
            'high' => Priority::HIGHT,
            'tinggi' => Priority::HIGHT,
            'medium' => Priority::MEDIUM,
            'sedang' => Priority::MEDIUM,
            'normal' => Priority::MEDIUM,
            'low' => Priority::LOW,
            'rendah' => Priority::LOW,
            'enhancement' => Priority::ENHANCEMENT,
            'peningkatan' => Priority::ENHANCEMENT,
        ];

        if (isset($aliases[$normalized])) {
            $aliased = Priority::find($aliases[$normalized]);
            if ($aliased) {
                return $aliased;
            }
        }

        return Priority::query()
            ->whereRaw('LOWER(name) = ?', [$normalized])
            ->first();
    }

    /**
     * Jawaban "1" berarti opsi ke-1 di daftar, bukan otomatis ID database 1.
     */
    public function resolveNumberedListChoice(string $field, string $value, array $formOptions): BusinessEntity|Unit|ProblemCategory|Priority|null
    {
        if ($value === '' || ! ctype_digit($value)) {
            return null;
        }

        $index = (int) $value;
        $items = array_slice($this->optionsForField($field, $formOptions), 0, self::LIST_DISPLAY_LIMIT);
        if ($index < 1 || $index > count($items)) {
            return null;
        }

        $item = $items[$index - 1];
        $id = (int) ($item['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }

        return match ($this->normalizeField($field)) {
            'business_entities_id' => BusinessEntity::find($id),
            'unit_id' => Unit::find($id),
            'problem_category_id' => ProblemCategory::find($id),
            'priority_id' => Priority::find($id),
            default => null,
        };
    }

    /**
     * @param  Collection<int, BusinessEntity|Unit|ProblemCategory|Priority>  $records
     * @return array{status: string, record?: BusinessEntity|Unit|ProblemCategory|Priority, candidates?: list<string>}
     */
    private function matchUniqueMasterRecord(string $message, $records): array
    {
        $hits = [];
        foreach ($records as $record) {
            $name = $this->text($record->name ?? '');
            if ($name !== '' && $this->messageMentionsName($message, $name)) {
                $hits[] = $record;
            }
        }

        if (count($hits) === 1) {
            return ['status' => 'matched', 'record' => $hits[0]];
        }

        if (count($hits) > 1) {
            return [
                'status' => 'ambiguous',
                'candidates' => array_values(array_unique(array_map(
                    fn ($record): string => (string) $record->name,
                    $hits,
                ))),
            ];
        }

        return ['status' => 'none'];
    }

    /**
     * @return array{status: string, record?: Priority, candidates?: list<string>}
     */
    private function matchUniquePriority(string $message): array
    {
        $hits = [];

        foreach (Priority::query()->orderBy('id')->get(['id', 'name']) as $priority) {
            if ($this->messageMentionsName($message, (string) $priority->name)) {
                $hits[$priority->id] = $priority;
            }
        }

        $hits = array_values($hits);
        if (count($hits) === 1) {
            return ['status' => 'matched', 'record' => $hits[0]];
        }
        if (count($hits) > 1) {
            return [
                'status' => 'ambiguous',
                'candidates' => array_map(fn (Priority $priority): string => (string) $priority->name, $hits),
            ];
        }

        return ['status' => 'none'];
    }

    private function messageMentionsName(string $message, string $name): bool
    {
        $haystack = Str::lower($message);
        $needle = Str::lower($this->text($name));
        if ($needle === '' || $haystack === '') {
            return false;
        }

        if (Str::length($needle) <= 3) {
            return (bool) preg_match('/(?<![a-z0-9])'.preg_quote($needle, '/').'(?![a-z0-9])/u', $haystack);
        }

        return str_contains($haystack, $needle);
    }

    private function titleFromMessage(string $message): string
    {
        $line = $this->text(strtok(str_replace(["\r\n", "\r"], "\n", $message), "\n") ?: $message);
        $line = preg_replace('/^(bos|min|admin|halo|hai|tolong|mohon)[,:\s]+/iu', '', $line) ?: $line;

        return Str::limit($this->text($line), 80, '');
    }

    /**
     * @return list<string>
     */
    private function missingCreateFields(array $ticketData, ?User $owner): array
    {
        $missing = [];
        foreach (['title', 'description'] as $field) {
            if ($this->text($ticketData[$field] ?? '') === '') {
                $missing[] = $field;
            }
        }

        $evaluation = $this->evaluate($ticketData, $owner);
        foreach ($evaluation['issues'] as $issue) {
            $missing[] = $issue['field'];
        }

        return array_values(array_unique($missing));
    }

    private function nextMissingCreateField(array $ticketData, ?User $owner): ?string
    {
        return $this->missingCreateFields($ticketData, $owner)[0] ?? null;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function optionsForField(string $field, array $formOptions): array
    {
        $items = match ($this->normalizeField($field)) {
            'business_entities_id' => $formOptions['business_entities'] ?? [],
            'unit_id' => $formOptions['units'] ?? [],
            'problem_category_id' => $formOptions['problem_categories'] ?? [],
            'priority_id' => $formOptions['priorities'] ?? [],
            default => [],
        };

        return array_values(array_filter(
            is_array($items) ? $items : [],
            fn ($item): bool => is_array($item) && $this->text($item['name'] ?? '') !== '',
        ));
    }

    private function buildValidatedMessage(
        string $field,
        string $resolvedName,
        ?string $nextField,
        array $formOptions,
        ?User $owner,
        array $ticketData,
    ): string {
        $label = $this->fieldLabel($field);
        $ack = "Baik, {$label} {$resolvedName}.";

        if (! $nextField || ! in_array($nextField, ['business_entities_id', 'unit_id', 'problem_category_id', 'priority_id'], true)) {
            return $ack;
        }

        $nextIssue = $this->fieldIssue($nextField, null);
        $nextPrompt = $this->buildFieldValidationMessage($nextIssue, $this->formOptionsForField($nextField, $formOptions, $ticketData, $owner));

        return "{$ack}\n\n{$nextPrompt}";
    }

    private function formOptionsForField(string $field, array $formOptions, array $ticketData, ?User $owner): array
    {
        if ($field !== 'problem_category_id') {
            return $formOptions;
        }

        $unit = $this->resolveUnit($ticketData, $owner);

        return $this->formOptionsService->formOptions($unit?->id);
    }

    private function nextMissingField(array $ticketData, ?User $owner): ?string
    {
        $evaluation = $this->evaluate($ticketData, $owner);

        return $evaluation['issues'][0]['field'] ?? null;
    }

    /**
     * @return array{field: string, reason: string, provided_value: ?string}
     */
    private function fieldIssue(string $field, ?string $providedValue): array
    {
        return [
            'field' => $field,
            'reason' => $providedValue !== null && $providedValue !== '' ? 'not_found' : 'missing',
            'provided_value' => $providedValue,
        ];
    }

    private function providedFieldValue(array $ticketData, array $idKeys, array $nameKeys): ?string
    {
        foreach ($idKeys as $key) {
            $raw = $ticketData[$key] ?? null;
            if ($raw === null || $raw === '') {
                continue;
            }

            if (is_numeric($raw) && (int) $raw > 0) {
                return (string) (int) $raw;
            }

            $text = $this->text($raw);
            if ($text !== '') {
                return $text;
            }
        }

        foreach ($nameKeys as $key) {
            $text = $this->text($ticketData[$key] ?? '');
            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }

    private function patchForField(string $field, string $value): array
    {
        if ($value === '') {
            return [];
        }

        if (ctype_digit($value)) {
            return match ($field) {
                'business_entities_id' => ['business_entities_id' => $value],
                'unit_id' => ['unit_id' => $value],
                'problem_category_id' => ['problem_category_id' => $value],
                'priority_id' => ['priority_id' => $value],
                default => [],
            };
        }

        return match ($field) {
            'business_entities_id' => ['business_entity' => $value, 'business_entities_id' => ''],
            'unit_id' => ['unit' => $value, 'unit_id' => ''],
            'problem_category_id' => ['problem_category' => $value, 'problem_category_id' => ''],
            'priority_id' => ['priority' => $value, 'priority_id' => ''],
            default => [],
        };
    }

    /**
     * @param  BusinessEntity|Unit|ProblemCategory|Priority  $model
     * @return array<string, string>
     */
    private function resolvedPatch(string $field, $model): array
    {
        return match ($field) {
            'business_entities_id' => [
                'business_entities_id' => (string) $model->id,
                'business_entity' => (string) $model->name,
            ],
            'unit_id' => [
                'unit_id' => (string) $model->id,
                'unit' => (string) $model->name,
            ],
            'problem_category_id' => [
                'problem_category_id' => (string) $model->id,
                'problem_category' => (string) $model->name,
            ],
            'priority_id' => [
                'priority_id' => (string) $model->id,
                'priority' => (string) $model->name,
            ],
            default => [],
        };
    }

    private function normalizeField(string $field): string
    {
        return match ($field) {
            'business_entity', 'business_entity_name', 'business_entity_id' => 'business_entities_id',
            'unit', 'unit_name' => 'unit_id',
            'problem_category', 'category', 'category_name', 'category_id' => 'problem_category_id',
            'priority' => 'priority_id',
            default => $field,
        };
    }

    private function fieldLabel(string $field): string
    {
        return match ($this->normalizeField($field)) {
            'business_entities_id' => 'Perusahaan/cabang',
            'unit_id' => 'Unit kerja',
            'problem_category_id' => 'Jenis masalah',
            'priority_id' => 'Prioritas',
            default => 'Data tiket',
        };
    }

    private function fieldQuestion(string $field): string
    {
        return match ($this->normalizeField($field)) {
            'business_entities_id' => 'Perusahaan/cabang tempat kejadian?',
            'unit_id' => 'Tim mana yang harus menangani?',
            'problem_category_id' => 'Jenis masalahnya apa?',
            'priority_id' => 'Seberapa mendesak?',
            default => 'Lengkapi data tiket.',
        };
    }

    private function formatFormOptionsList(string $field, array $formOptions, int $limit = self::LIST_DISPLAY_LIMIT): string
    {
        $items = $this->optionsForField($field, $formOptions);
        $visible = array_slice($items, 0, $limit);
        $lines = [];
        foreach ($visible as $index => $item) {
            $lines[] = ($index + 1).'. '.$this->text($item['name'] ?? '');
        }

        $block = implode("\n", $lines);
        if (count($items) > $limit) {
            $block .= "\n\nDitampilkan {$limit} pertama. Ketik nama yang tertulis jika tidak ada di daftar.";
        }

        return $block;
    }

    private function text(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }
}
