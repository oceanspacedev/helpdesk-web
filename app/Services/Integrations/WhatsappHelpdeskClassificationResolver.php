<?php

namespace App\Services\Integrations;

use App\Models\BusinessEntity;
use App\Models\Priority;
use App\Models\ProblemCategory;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Str;

class WhatsappHelpdeskClassificationResolver
{
    public function __construct(
        private WhatsappHelpdeskFormOptionsService $formOptionsService,
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
     * @return array{
     *     ok: bool,
     *     field: string,
     *     reason: string,
     *     provided_value: ?string,
     *     resolved: ?array{id: int, name: string, ticket_data_patch: array<string, string>},
     *     message: string,
     *     form_options: array<string, mixed>,
     *     next_field: ?string
     * }
     */
    public function validateField(string $field, mixed $value, array $ticketContext = [], ?User $owner = null): array
    {
        $field = $this->normalizeField($field);
        $providedValue = $this->text($value);
        $ticketData = array_merge($ticketContext, $this->patchForField($field, $providedValue));
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
                ? "{$label} dengan ID {$provided} tidak ditemukan di Helpdesk."
                : "{$label} \"{$provided}\" tidak ditemukan di Helpdesk.";
        } else {
            $intro = "{$label} belum dipilih.";
        }

        if ($optionsBlock === '') {
            return $intro;
        }

        return "{$intro}\n\nPilih salah satu:\n{$optionsBlock}\n\nBalas nomor atau tulis nama persis seperti di daftar.";
    }

    public function nextQuestionForField(string $field): string
    {
        return match ($this->normalizeField($field)) {
            'business_entities_id' => 'Entitas bisnis/cabang apa? Contoh: Complete Selular.',
            'unit_id' => 'Unit kerja yang menangani? Contoh: IT.',
            'problem_category_id' => 'Kategori masalah sesuai form Helpdesk? Contoh: Akses Akun.',
            'priority_id' => 'Prioritas tiket? Pilih Low, Medium, High, Critical, atau Enhancement.',
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
            } catch (\Throwable) {
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
            return Priority::find($aliases[$normalized]);
        }

        return Priority::query()
            ->whereRaw('LOWER(name) = ?', [$normalized])
            ->first();
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
            'business_entities_id' => 'Entitas bisnis',
            'unit_id' => 'Unit kerja',
            'problem_category_id' => 'Kategori masalah',
            'priority_id' => 'Prioritas',
            default => 'Data tiket',
        };
    }

    private function formatFormOptionsList(string $field, array $formOptions, int $limit = 8): string
    {
        $items = match ($this->normalizeField($field)) {
            'business_entities_id' => $formOptions['business_entities'] ?? [],
            'unit_id' => $formOptions['units'] ?? [],
            'problem_category_id' => $formOptions['problem_categories'] ?? [],
            'priority_id' => $formOptions['priorities'] ?? [],
            default => [],
        };

        $lines = [];
        foreach (array_slice($items, 0, $limit) as $index => $item) {
            $name = $this->text(is_array($item) ? ($item['name'] ?? '') : '');
            if ($name === '') {
                continue;
            }

            $lines[] = ($index + 1).'. '.$name;
        }

        return implode("\n", $lines);
    }

    private function text(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }
}
