<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\EmployeeService;
use App\Services\Integrations\HelpdeskReporterRegistrationService;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\Concerns\CreatesHelpdeskIntegrationSchema;
use Tests\TestCase;

class HelpdeskReporterRegistrationServiceTest extends TestCase
{
    use CreatesHelpdeskIntegrationSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTestSchema();
        Cache::flush();
    }

    public function test_it_creates_a_restricted_phone_only_reporter(): void
    {
        $employees = Mockery::mock(EmployeeService::class);
        $employees->shouldReceive('findAllByPhone')
            ->once()
            ->with('6281234560001')
            ->andReturn([]);

        $result = (new HelpdeskReporterRegistrationService($employees))
            ->createPhoneOnlyForVerifiedPhone('0812-3456-0001', '<b>Reporter   Baru</b>');

        $this->assertTrue($result['ok']);
        $this->assertSame('created_manual', $result['status']);
        $this->assertTrue($result['created']);
        $this->assertInstanceOf(User::class, $result['user']);

        $user = $result['user']->fresh();
        $this->assertSame('Reporter Baru', $user->name);
        $this->assertSame('6281234560001', $user->phone);
        $this->assertSame('6281234560001', $user->phone_normalized);
        $this->assertNull($user->email);
        $this->assertNull($user->password);
        $this->assertNull($user->email_verified_at);
        $this->assertNull($user->email_verified_via);
        $this->assertNull($user->identity);
        $this->assertTrue($user->is_active);
        $this->assertSame(1, User::query()->count());
    }

    public function test_it_reuses_an_active_race_winner_without_overwriting_its_name(): void
    {
        $winner = User::query()->create([
            'name' => 'Nama Autoritatif',
            'email' => null,
            'password' => null,
            'phone' => '0812-3456-0002',
            'is_active' => true,
        ]);
        $employees = Mockery::mock(EmployeeService::class);
        $employees->shouldNotReceive('findAllByPhone');

        $result = (new HelpdeskReporterRegistrationService($employees))
            ->createPhoneOnlyForVerifiedPhone('6281234560002', 'Nama Tidak Boleh Menimpa');

        $this->assertTrue($result['ok']);
        $this->assertSame('existing', $result['status']);
        $this->assertFalse($result['created']);
        $this->assertSame($winner->id, $result['user']?->id);
        $this->assertSame('Nama Autoritatif', $winner->fresh()->name);
        $this->assertSame(1, User::query()->count());
    }

    public function test_it_blocks_inactive_and_soft_deleted_accounts(): void
    {
        User::query()->create([
            'name' => 'Reporter Nonaktif',
            'email' => null,
            'password' => null,
            'phone' => '0812-3456-0003',
            'is_active' => false,
        ]);
        $deleted = User::query()->create([
            'name' => 'Reporter Terhapus',
            'email' => null,
            'password' => null,
            'phone' => '0812-3456-0004',
            'is_active' => true,
        ]);
        $deleted->delete();

        $employees = Mockery::mock(EmployeeService::class);
        $employees->shouldNotReceive('findAllByPhone');
        $service = new HelpdeskReporterRegistrationService($employees);

        $inactive = $service->createPhoneOnlyForVerifiedPhone('6281234560003', 'Nama Baru Nonaktif');
        $softDeleted = $service->createPhoneOnlyForVerifiedPhone('6281234560004', 'Nama Baru Terhapus');

        foreach ([$inactive, $softDeleted] as $result) {
            $this->assertFalse($result['ok']);
            $this->assertSame('helpdesk_inactive', $result['status']);
            $this->assertFalse($result['created']);
            $this->assertNull($result['user']);
        }
        $this->assertSame(2, User::query()->withTrashed()->count());
        $this->assertSame(1, User::query()->count());
    }

    public function test_it_rejects_ambiguous_normalized_helpdesk_accounts(): void
    {
        User::query()->create([
            'name' => 'Reporter Format Internasional',
            'email' => null,
            'password' => null,
            'phone' => '6281234560005',
            'is_active' => true,
        ]);
        User::query()->create([
            'name' => 'Reporter Format Lokal',
            'email' => null,
            'password' => null,
            'phone' => '0812-3456-0005',
            'is_active' => true,
        ]);

        $employees = Mockery::mock(EmployeeService::class);
        $employees->shouldNotReceive('findAllByPhone');

        $result = (new HelpdeskReporterRegistrationService($employees))
            ->createPhoneOnlyForVerifiedPhone('6281234560005', 'Reporter Ketiga');

        $this->assertFalse($result['ok']);
        $this->assertSame('helpdesk_ambiguous', $result['status']);
        $this->assertFalse($result['created']);
        $this->assertNull($result['user']);
        $this->assertSame(2, User::query()->count());
    }

    public function test_it_fails_closed_when_talenta_appears_during_registration(): void
    {
        $employees = Mockery::mock(EmployeeService::class);
        $employees->shouldReceive('findAllByPhone')
            ->once()
            ->with('6281234560006')
            ->andReturn([[
                'id_employee' => 'EMP-60006',
                'first_name' => 'Reporter',
                'last_name' => 'Talenta',
                'mobile_phone' => '0812-3456-0006',
            ]]);

        $result = (new HelpdeskReporterRegistrationService($employees))
            ->createPhoneOnlyForVerifiedPhone('0812-3456-0006', 'Reporter Manual');

        $this->assertFalse($result['ok']);
        $this->assertSame('directory_changed', $result['status']);
        $this->assertFalse($result['created']);
        $this->assertNull($result['user']);
        $this->assertSame(0, User::query()->count());
    }

    public function test_it_rejects_ambiguous_talenta_matches(): void
    {
        $employees = Mockery::mock(EmployeeService::class);
        $employees->shouldReceive('findAllByPhone')
            ->once()
            ->with('6281234560007')
            ->andReturn([
                ['id_employee' => 'EMP-60007-A'],
                ['id_employee' => 'EMP-60007-B'],
            ]);

        $result = (new HelpdeskReporterRegistrationService($employees))
            ->createPhoneOnlyForVerifiedPhone('0812-3456-0007', 'Reporter Manual');

        $this->assertFalse($result['ok']);
        $this->assertSame('talenta_ambiguous', $result['status']);
        $this->assertFalse($result['created']);
        $this->assertNull($result['user']);
        $this->assertSame(0, User::query()->count());
    }

    public function test_it_rejects_invalid_names_before_any_directory_lookup(): void
    {
        $employees = Mockery::mock(EmployeeService::class);
        $employees->shouldNotReceive('findAllByPhone');
        $service = new HelpdeskReporterRegistrationService($employees);

        foreach (['', 'ya', '123456', '6281234567890', '<script></script>'] as $name) {
            $result = $service->createPhoneOnlyForVerifiedPhone('0812-3456-0008', $name);

            $this->assertFalse($result['ok']);
            $this->assertSame('invalid_name', $result['status']);
            $this->assertFalse($result['created']);
            $this->assertNull($result['user']);
        }
        $this->assertSame(0, User::query()->count());
    }
}
