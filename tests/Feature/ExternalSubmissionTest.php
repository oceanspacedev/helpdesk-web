<?php

namespace Tests\Feature;

use App\Filament\Resources\ExternalSubmissionResource;
use App\Filament\Resources\ExternalSubmissionResource\Pages\ManageExternalSubmissions;
use App\Models\ExternalSubmission;
use App\Models\Integration;
use App\Models\ProblemCategory;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use App\Services\Integrations\External\ExternalTicketService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesHelpdeskIntegrationSchema;
use Tests\TestCase;

/**
 * "Pengajuan Saya": jejak pengajuan pass-through (kode referensi + tautan
 * lacak) tanpa menyimpan isi tiketnya di helpdesk.
 */
class ExternalSubmissionTest extends TestCase
{
    use CreatesHelpdeskIntegrationSchema;

    private const BASE = 'https://od.test/api/helpdesk/v1';

    private Integration $integration;

    private ProblemCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTestSchema();
        Cache::flush();
        Http::preventStrayRequests();

        $this->integration = Integration::query()->create(['name' => 'Modul OD', 'base_url' => self::BASE, 'token' => 't', 'webhook_secret' => 's', 'is_active' => true, 'timeout_seconds' => 10]);
        $unit = Unit::query()->create(['name' => 'BUSDEV', 'integration_id' => $this->integration->id]);
        $this->category = ProblemCategory::query()->create(['unit_id' => $unit->id, 'name' => 'Instruksi Kerja', 'external_key' => 'IK']);
        foreach (['Super Admin', 'User'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_a_forwarded_submission_leaves_only_a_reference_and_track_link(): void
    {
        $reporter = $this->user('rina@example.test', 'User');
        Http::fake([self::BASE.'/tickets' => Http::response(['data' => $this->snapshot('IK-1', 'open', 'Pengajuan diterima')], 201)]);

        $service = app(ExternalTicketService::class);
        $service->submit($this->category, $reporter, 'IK Penerimaan Barang', ['a' => 'b'], [], 'idem-1');
        // Kirim ulang idempoten tidak menggandakan jejak.
        $service->submit($this->category, $reporter, 'IK Penerimaan Barang', ['a' => 'b'], [], 'idem-1');

        $submission = ExternalSubmission::query()->sole();
        $this->assertSame('IK-1', $submission->external_ref);
        $this->assertSame('IK Penerimaan Barang', $submission->title);
        $this->assertSame('Instruksi Kerja', $submission->category_name);
        $this->assertSame($reporter->id, $submission->user_id);
        $this->assertSame('open', $submission->status_code);
        $this->assertSame('https://od.test/lacak?kode=IK-1', $submission->track_url);
        $this->assertSame(0, Ticket::query()->withTrashed()->count(), 'Isi pengajuan tetap tidak disimpan sebagai tiket.');
    }

    public function test_recording_never_breaks_the_submission(): void
    {
        Schema::drop('external_submissions');
        Http::fake([self::BASE.'/tickets' => Http::response(['data' => $this->snapshot('IK-2', 'open', 'Pengajuan diterima')], 201)]);

        $result = app(ExternalTicketService::class)->submit($this->category, $this->user('a@example.test', 'User'), 'IK', ['a' => 'b'], [], 'idem-2');

        $this->assertSame('IK-2', $result['ref'], 'Deploy sebelum migrasi: pengajuan tetap berhasil.');
    }

    public function test_reporters_see_their_own_submissions_and_admins_see_all(): void
    {
        $rina = $this->user('rina@example.test', 'User');
        $budi = $this->user('budi@example.test', 'User');
        $admin = $this->user('admin@example.test', 'Super Admin');
        $mine = $this->submission($rina, 'IK-RINA');
        $theirs = $this->submission($budi, 'IK-BUDI');

        $this->actingAs($rina);
        $this->assertTrue(ExternalSubmissionResource::canViewAny());
        $this->assertFalse(ExternalSubmissionResource::canCreate());
        $this->assertFalse(ExternalSubmissionResource::canDelete($mine));
        Livewire::test(ManageExternalSubmissions::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs])
            ->assertTableActionHasUrl('track', 'https://od.test/lacak?kode=IK-RINA', $mine);

        $this->actingAs($admin);
        Livewire::test(ManageExternalSubmissions::class)->assertCanSeeTableRecords([$mine, $theirs]);
    }

    public function test_status_can_be_refreshed_from_the_external_system(): void
    {
        $rina = $this->user('rina@example.test', 'User');
        $submission = $this->submission($rina, 'IK-3');
        $this->actingAs($rina);

        Http::fake([self::BASE.'/tickets/IK-3' => Http::response(['data' => $this->snapshot('IK-3', 'in_progress', 'Drafting')])]);
        Livewire::test(ManageExternalSubmissions::class)
            ->callAction(TestAction::make('refreshStatus')->table($submission))
            ->assertNotified('Status: Drafting');

        $this->assertSame('in_progress', $submission->fresh()->status_code);
        $this->assertSame('Drafting', $submission->fresh()->status_label);

        // Sistem tujuan mati: status lama dipertahankan.
        $down = $this->submission($rina, 'IK-4');
        Http::fake([self::BASE.'/tickets/IK-4' => Http::response([], 503)]);
        Livewire::test(ManageExternalSubmissions::class)
            ->callAction(TestAction::make('refreshStatus')->table($down))
            ->assertNotified('Status belum bisa diperbarui');
        $this->assertSame('open', $down->fresh()->status_code);
    }

    private function submission(User $user, string $ref): ExternalSubmission
    {
        return ExternalSubmission::query()->create([
            'integration_id' => $this->integration->id,
            'user_id' => $user->id,
            'unit_id' => $this->category->unit_id,
            'category_name' => 'Instruksi Kerja',
            'external_ref' => $ref,
            'title' => 'IK '.$ref,
            'status_code' => 'open',
            'status_label' => 'Pengajuan diterima',
            'track_url' => 'https://od.test/lacak?kode='.$ref,
            'status_checked_at' => now(),
        ]);
    }

    private function user(string $email, string $role): User
    {
        $user = User::query()->create(['name' => ucfirst(strtok($email, '@')), 'email' => $email, 'phone' => '0812'.random_int(10000000, 99999999), 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(string $ref, string $code, string $label): array
    {
        return [
            'ref' => $ref,
            'track_url' => 'https://od.test/lacak?kode='.$ref,
            'status' => ['code' => $code, 'label' => $label],
            'summary' => [],
            'items' => [],
            'timeline' => [],
            'comments' => [],
            'files' => [],
            'updated_at' => '2026-11-26T10:00:00+07:00',
        ];
    }
}
