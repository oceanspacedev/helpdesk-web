<?php

namespace App\Services\Integrations;

use App\Models\BusinessEntity;
use App\Models\Priority;
use App\Models\ProblemCategory;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Maps reporter language onto the live Helpdesk master data.
 *
 * Routing follows how staff already file tickets (Odoo, CSA, printer/laptop,
 * CCTV, jaringan, dokumen BUSDEV). The MCP host must not choose these values.
 */
class HelpdeskOperationalClassifier
{
    public const UNCLASSIFIED_CATEGORY = 'Perlu diklasifikasi';

    public const UNSTATED_ENTITY = 'Belum disebutkan';

    public const OD_LEAD_USER_NAME = 'Organization Development';

    public const OD_STAFF_USER_NAME = 'Organization Development Staff';

    /**
     * BUSDEV document types historically owned by the OD lead.
     *
     * @var list<string>
     */
    public const BUSDEV_LEAD_OD_CATEGORIES = [
        'Standard Operating Procedure (SOP)',
        'Surat Edaran (SE)',
        'Memo Internal (MI)',
        'Script',
        'Struktur Organisasi',
        'Request Lainnya',
        'SURAT PEMBERITAHUAN',
    ];

    /**
     * BUSDEV document types historically owned by OD staff.
     *
     * @var list<string>
     */
    public const BUSDEV_STAFF_OD_CATEGORIES = [
        'Instruksi Kerja (IK)',
        'Job Description (JD)',
    ];

    /**
     * @var list<string>
     */
    private const TITLE_PREFIXES = [
        'buat laporan helpdesk',
        'bikin laporan helpdesk',
        'buatkan laporan helpdesk',
        'buat laporan',
        'buatkan laporan',
        'bikin laporan',
        'buat tiket',
        'bikin tiket',
        'buatkan tiket',
        'catat tiket',
        'buka tiket',
        'create ticket',
        'open ticket',
        'submit ticket',
        'file a ticket',
        'raise a ticket',
        'report issue',
        'new ticket',
        'mau lapor',
        'saya lapor',
        '/helpdesk',
        '/ticket',
        '/lapor',
        'lapor',
    ];

    /**
     * More specific routes first. Keywords come from existing ticket titles.
     *
     * @var list<array{unit: string, category: string, system: string, keywords: list<string>}>
     */
    private const ISSUE_ROUTES = [
        [
            'unit' => 'IT',
            'category' => 'CCTV',
            'system' => 'CCTV',
            'keywords' => ['cctv'],
        ],
        [
            'unit' => 'IT',
            'category' => 'Laptop, Komputer, Printer',
            'system' => 'Printer / komputer',
            'keywords' => [
                'ngeprint', 'mencetak', 'dicetak', 'tercetak', 'printer',
                'print out', 'print', 'scanner', 'laptop', 'komputer',
                'keyboard', 'mouse', 'monitor', 'pc rusak', 'pc',
            ],
        ],
        [
            'unit' => 'IT',
            'category' => 'CSA Program',
            'system' => 'CSA',
            'keywords' => [
                'csa', 'creat user', 'create user', 'nonaktif gudang',
                'non aktif gudang', 'penonaktifan gudang', 'gudang sales',
            ],
        ],
        [
            'unit' => 'IT',
            'category' => 'Odoo Program',
            'system' => 'Odoo',
            'keywords' => [
                'odoo', 'tidak bisa validate', 'tidak validate',
            ],
        ],
        [
            'unit' => 'IT',
            'category' => 'MSC Program',
            'system' => 'MSC',
            'keywords' => ['msc'],
        ],
        [
            'unit' => 'IT',
            'category' => 'Jaringan',
            'system' => 'Jaringan',
            'keywords' => ['wifi', 'vpn', 'jaringan', 'internet'],
        ],
        [
            'unit' => 'BUSDEV',
            'category' => 'Standard Operating Procedure (SOP)',
            'system' => 'SOP',
            'keywords' => ['sop'],
        ],
        [
            'unit' => 'BUSDEV',
            'category' => 'Surat Edaran (SE)',
            'system' => 'Surat Edaran',
            'keywords' => ['surat edaran'],
        ],
        [
            'unit' => 'BUSDEV',
            'category' => 'Memo Internal (MI)',
            'system' => 'Memo Internal',
            'keywords' => ['memo internal', 'internal memo'],
        ],
        [
            'unit' => 'BUSDEV',
            'category' => 'Instruksi Kerja (IK)',
            'system' => 'Instruksi Kerja',
            'keywords' => ['instruksi kerja'],
        ],
    ];

    /**
     * @var list<array{entity: string, keywords: list<string>}>
     */
    private const ENTITY_ALIASES = [
        ['entity' => 'Complete Kulinari', 'keywords' => ['kulinari', 'complete kulinari']],
        ['entity' => 'CV. MAJU TECNOLOGI', 'keywords' => ['maju tecnologi', 'maju technology', 'cv maju']],
        ['entity' => 'CV. TOP', 'keywords' => ['cv. top', 'cv top', 'cvtop']],
        ['entity' => 'PT. MKLI', 'keywords' => ['mkli']],
        ['entity' => 'PT. RISM', 'keywords' => ['rism']],
        ['entity' => 'PT. MSI', 'keywords' => ['pt. msi', 'pt msi']],
        ['entity' => 'Trenly', 'keywords' => ['trenly']],
        ['entity' => 'AFILIASI', 'keywords' => ['afiliasi']],
        ['entity' => 'CV. CS', 'keywords' => ['cv. cs', 'cv cs', 'complete selular']],
    ];

    /**
     * @return array{title: string, description: string, location: string, affected_system: string}
     */
    public function normalizeIssue(string $message): array
    {
        $description = $this->stripIntakePrefix($this->text($message));
        $title = $description === '' ? '' : Str::limit($this->headline($description), 120, '');
        $location = $this->extractLocation($description);

        return [
            'title' => $title !== '' ? $title : 'Laporan Helpdesk',
            'description' => $description,
            'location' => $location,
            'affected_system' => '',
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function classify(string $message, array $state = [], ?User $owner = null): array
    {
        $normalized = $this->normalizeIssue($message);
        $haystack = Str::lower($normalized['description']);
        $route = $this->matchRoute($haystack);

        $patch = [
            'title' => $normalized['title'],
            'description' => $normalized['description'],
            'location' => $normalized['location'],
            'affected_system' => $route['system'] ?? $normalized['affected_system'],
            'category_uncertain' => false,
            'unit_assumed' => false,
            'priority_assumed' => false,
            'entity_assumed' => false,
            'entity_source' => '',
        ];

        if ($route !== null) {
            $unit = $this->findUnit($route['unit']);
            if ($unit) {
                $patch['unit_id'] = (string) $unit->id;
                $patch['unit'] = (string) $unit->name;
                $category = $this->findCategory((int) $unit->id, $route['category']);
                if ($category) {
                    $patch['problem_category_id'] = (string) $category->id;
                    $patch['problem_category'] = (string) $category->name;
                    $patch['affected_system'] = $route['system'];
                }
            }
        }

        $urgent = $this->mentionsAny($haystack, ['critical', 'urgent', 'darurat']);
        $priority = $urgent ? $this->urgentPriority() : $this->defaultPriority();
        if ($priority) {
            $patch['priority_id'] = (string) $priority->id;
            $patch['priority'] = (string) $priority->name;
            $looksUrgent = Str::contains(Str::lower($priority->name), ['critical', 'urgent']);
            $patch['priority_assumed'] = ! ($urgent && $looksUrgent);
        }

        $entity = $this->entityFromMessage($haystack);
        if ($entity) {
            $patch['entity_source'] = 'message';
        } else {
            $entity = $this->entityForOwner($owner ?? $this->ownerFromState($state));
            if ($entity) {
                $patch['entity_source'] = 'owner';
            }
        }
        if (! $entity) {
            $entity = $this->unstatedEntity();
            $patch['entity_assumed'] = true;
            $patch['entity_source'] = 'unstated';
        }
        if ($entity) {
            $patch['business_entities_id'] = (string) $entity->id;
            $patch['business_entity'] = (string) $entity->name;
        }

        if (! isset($patch['unit_id'])) {
            $unit = $this->defaultUnit();
            if ($unit) {
                $patch['unit_id'] = (string) $unit->id;
                $patch['unit'] = (string) $unit->name;
                $patch['unit_assumed'] = true;
            }
        }

        if (! isset($patch['problem_category_id']) && isset($patch['unit_id'])) {
            $category = $this->unclassifiedCategory((int) $patch['unit_id']);
            if ($category) {
                $patch['problem_category_id'] = (string) $category->id;
                $patch['problem_category'] = (string) $category->name;
                $patch['category_uncertain'] = true;
            }
        }

        return $patch;
    }

    /**
     * Default processor for a live category name. Odoo, printer, and holding
     * tickets stay on the unit queue; only named BUSDEV document types still
     * route to the OD accounts.
     */
    public static function defaultAssigneeName(string $categoryName): ?string
    {
        $name = trim($categoryName);
        if ($name === '' || strcasecmp($name, self::UNCLASSIFIED_CATEGORY) === 0) {
            return null;
        }

        foreach (self::BUSDEV_LEAD_OD_CATEGORIES as $item) {
            if (strcasecmp($name, $item) === 0) {
                return self::OD_LEAD_USER_NAME;
            }
        }

        foreach (self::BUSDEV_STAFF_OD_CATEGORIES as $item) {
            if (strcasecmp($name, $item) === 0) {
                return self::OD_STAFF_USER_NAME;
            }
        }

        return null;
    }

    public function stripIntakePrefix(string $message): string
    {
        $remaining = $this->text($message);
        $prefixes = self::TITLE_PREFIXES;
        usort($prefixes, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        $guard = 0;
        while ($remaining !== '' && $guard < 4) {
            $guard++;
            $matched = false;
            foreach ($prefixes as $prefix) {
                $pattern = '/^'.preg_quote($prefix, '/').'(?![a-z0-9])/iu';
                if (! preg_match($pattern, $remaining, $matches)) {
                    continue;
                }
                $remaining = $this->text(substr($remaining, strlen($matches[0])));
                $remaining = ltrim($remaining, " \t.,:;!-");
                $matched = true;
                break;
            }
            if (! $matched) {
                break;
            }
        }

        return $remaining;
    }

    /**
     * @return array{unit: string, category: string, system: string, keywords: list<string>}|null
     */
    private function matchRoute(string $haystack): ?array
    {
        foreach (self::ISSUE_ROUTES as $route) {
            if ($this->mentionsAny($haystack, $route['keywords'])) {
                return $route;
            }
        }

        return null;
    }

    private function urgentPriority(): ?Priority
    {
        return Priority::query()
            ->where(function ($query): void {
                $query->whereRaw('LOWER(name) like ?', ['%critical%'])
                    ->orWhereRaw('LOWER(name) like ?', ['%urgent%']);
            })
            ->orderBy('id')
            ->first()
            ?: $this->defaultPriority();
    }

    private function defaultPriority(): ?Priority
    {
        return Priority::query()->whereRaw('LOWER(name) like ?', ['%medium%'])->orderBy('id')->first()
            ?: Priority::query()->orderBy('id')->first();
    }

    private function entityFromMessage(string $haystack): ?BusinessEntity
    {
        $aliasHits = [];
        foreach (self::ENTITY_ALIASES as $alias) {
            if (! $this->mentionsAny($haystack, $alias['keywords'])) {
                continue;
            }
            $entity = $this->findEntity($alias['entity']);
            if ($entity) {
                $aliasHits[$entity->id] = $entity;
            }
        }

        if (count($aliasHits) === 1) {
            return array_values($aliasHits)[0];
        }
        if (count($aliasHits) > 1) {
            return null;
        }

        $nameHits = [];
        foreach (BusinessEntity::query()->orderByDesc(DB::raw('LENGTH(name)'))->get(['id', 'name']) as $entity) {
            $name = Str::lower(trim((string) $entity->name));
            if (Str::length($name) < 4 || $name === Str::lower(self::UNSTATED_ENTITY)) {
                continue;
            }
            if ($this->mentions($haystack, $name)) {
                $nameHits[$entity->id] = $entity;
            }
        }

        if (count($nameHits) === 1) {
            return array_values($nameHits)[0];
        }

        return null;
    }

    private function entityForOwner(?User $owner): ?BusinessEntity
    {
        if (! $owner) {
            return null;
        }

        $unstated = $this->findEntity(self::UNSTATED_ENTITY);
        $entityId = Ticket::query()
            ->where('owner_id', $owner->id)
            ->whereNotNull('business_entities_id')
            ->where('description', 'not like', '%Dilaporkan via%')
            ->when($unstated, fn ($query) => $query->where('business_entities_id', '!=', $unstated->id))
            ->orderByDesc('id')
            ->value('business_entities_id');

        return $entityId ? BusinessEntity::query()->find($entityId) : null;
    }

    private function unstatedEntity(): ?BusinessEntity
    {
        $existing = BusinessEntity::withTrashed()
            ->whereRaw('LOWER(name) = ?', [Str::lower(self::UNSTATED_ENTITY)])
            ->orderBy('id')
            ->first();
        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            return $existing;
        }

        return BusinessEntity::query()->create([
            'name' => self::UNSTATED_ENTITY,
        ]);
    }

    private function defaultUnit(): ?Unit
    {
        return $this->findUnit('IT')
            ?: Unit::query()->orderBy('id')->first();
    }

    private function unclassifiedCategory(int $unitId): ?ProblemCategory
    {
        $existing = ProblemCategory::withTrashed()
            ->where('unit_id', $unitId)
            ->whereRaw('LOWER(name) = ?', [Str::lower(self::UNCLASSIFIED_CATEGORY)])
            ->orderBy('id')
            ->first();
        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            return $existing;
        }

        return ProblemCategory::query()->create([
            'unit_id' => $unitId,
            'name' => self::UNCLASSIFIED_CATEGORY,
        ]);
    }

    private function findUnit(string $name): ?Unit
    {
        return Unit::query()->whereRaw('LOWER(name) = ?', [Str::lower($name)])->orderBy('id')->first();
    }

    private function findCategory(int $unitId, string $name): ?ProblemCategory
    {
        return ProblemCategory::query()
            ->where('unit_id', $unitId)
            ->whereRaw('LOWER(name) = ?', [Str::lower($name)])
            ->orderBy('id')
            ->first();
    }

    private function findEntity(string $name): ?BusinessEntity
    {
        return BusinessEntity::query()->whereRaw('LOWER(name) = ?', [Str::lower($name)])->orderBy('id')->first();
    }

    private function ownerFromState(array $state): ?User
    {
        $id = (int) ($state['helpdesk_user_id'] ?? 0);

        return $id > 0 ? User::query()->find($id) : null;
    }

    private function extractLocation(string $message): string
    {
        if (preg_match('/\b(ruang\s+it|ruang\s+[a-z0-9]+|cabang\s+[a-z0-9]+|lantai\s+[a-z0-9]+|lt\.?\s*[a-z0-9]+|kasir|gudang\s+[a-z0-9]+|toko\s+[a-z0-9]+|ho\s+[a-z0-9]+)\b/iu', $message, $matches)) {
            return $this->headline($matches[1]);
        }

        return '';
    }

    private function headline(string $text): string
    {
        $line = $this->text(strtok(str_replace(["\r\n", "\r"], "\n", $text), "\n") ?: $text);
        $line = preg_replace('/^(bos|min|admin|halo|hai|tolong|mohon)[,:\s]+/iu', '', $line) ?: $line;
        $line = $this->text($line);
        if ($line === '') {
            return '';
        }

        return Str::ucfirst($line);
    }

    /**
     * @param  list<string>  $needles
     */
    private function mentionsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($this->mentions($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function mentions(string $haystack, string $needle): bool
    {
        $needle = Str::lower($this->text($needle));
        if ($needle === '' || $haystack === '') {
            return false;
        }

        if (str_contains($needle, ' ')) {
            return str_contains($haystack, $needle);
        }

        return (bool) preg_match('/(?<![a-z0-9])'.preg_quote($needle, '/').'(?![a-z0-9])/u', $haystack);
    }

    private function text(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?: $value);
    }
}
