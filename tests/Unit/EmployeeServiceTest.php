<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\EmployeeService;
use DomainException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EmployeeServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('email_verified_via')->nullable();
            $table->string('password')->nullable();
            $table->string('identity')->nullable();
            $table->string('phone', 20)->nullable()->unique();
            $table->string('phone_normalized', 20)->nullable()->index();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function test_duplicate_talenta_phone_is_rejected_instead_of_choosing_the_first_employee(): void
    {
        $temporary = tempnam(sys_get_temp_dir(), 'talenta-duplicate-');
        $path = $temporary.'.json';
        File::put($path, json_encode([
            'data' => ['data' => [
                [
                    'id_employee' => 'EMP-A',
                    'first_name' => 'User',
                    'last_name' => 'A',
                    'mobile_phone' => '0812-3456-7890',
                ],
                [
                    'id_employee' => 'EMP-B',
                    'first_name' => 'User',
                    'last_name' => 'B',
                    'mobile_phone' => '6281234567890',
                ],
            ]],
        ], JSON_THROW_ON_ERROR));
        config()->set('services.talenta.employee_file', $path);
        Cache::flush();

        try {
            $employees = new EmployeeService;

            $this->assertCount(2, $employees->findAllByPhone('0812-3456-7890'));
            $this->assertNull($employees->findByPhone('6281234567890'));
        } finally {
            File::delete([$path, $temporary]);
        }
    }

    public function test_create_user_rejects_active_inactive_and_deleted_normalized_phone_matches(): void
    {
        $phones = [
            ['raw' => '+62 812-3456-7101', 'talenta' => '081234567101', 'active' => true, 'deleted' => false],
            ['raw' => '0812-3456-7102', 'talenta' => '6281234567102', 'active' => false, 'deleted' => false],
            ['raw' => '62812 3456 7103', 'talenta' => '+62 812-3456-7103', 'active' => true, 'deleted' => true],
        ];

        foreach ($phones as $index => $fixture) {
            $user = User::query()->create([
                'name' => 'Existing '.($index + 1),
                'email' => "existing-{$index}@example.test",
                'phone' => $fixture['raw'],
                'is_active' => $fixture['active'],
            ]);
            if ($fixture['deleted']) {
                $user->delete();
            }
        }

        $service = new EmployeeService;
        foreach ($phones as $index => $fixture) {
            try {
                $service->createUser([
                    'id_employee' => 'EMP-'.($index + 1),
                    'first_name' => 'Duplicate',
                    'last_name' => (string) ($index + 1),
                    'mobile_phone' => $fixture['talenta'],
                ], 'secret-password');
                $this->fail('Expected a canonical phone collision to be rejected.');
            } catch (DomainException $exception) {
                $this->assertSame('Nomor HP sudah terdaftar.', $exception->getMessage());
            }
        }

        $this->assertSame(3, User::withTrashed()->count());
    }

    public function test_create_user_uses_the_verified_alternate_talenta_phone(): void
    {
        $user = (new EmployeeService)->createUser([
            'id_employee' => 'EMP-ALT',
            'first_name' => 'Nomor',
            'last_name' => 'Alternatif',
            'mobile_phone' => '0812-0000-1111',
            'phone' => '+62 812-3456-7890',
        ], 'secret-password', '0812 3456 7890');

        $this->assertSame('6281234567890', $user->phone);
        $this->assertSame('6281234567890', $user->phone_normalized);
        $this->assertSame('EMP-ALT', $user->identity);
    }

    public function test_create_user_rejects_a_verified_phone_absent_from_talenta_record(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Nomor HP terverifikasi tidak cocok dengan data karyawan.');

        (new EmployeeService)->createUser([
            'id_employee' => 'EMP-MISMATCH',
            'first_name' => 'Nomor',
            'last_name' => 'Berbeda',
            'mobile_phone' => '0812-0000-1111',
        ], 'secret-password', '0812-0000-2222');
    }

    public function test_create_user_drops_email_that_belongs_to_active_or_deleted_user(): void
    {
        User::query()->create([
            'name' => 'Email Aktif',
            'email' => 'active@example.test',
            'phone' => '6281200007001',
            'is_active' => true,
        ]);
        $deleted = User::query()->create([
            'name' => 'Email Terhapus',
            'email' => 'deleted@example.test',
            'phone' => '6281200007002',
            'is_active' => true,
        ]);
        $deleted->delete();

        $service = new EmployeeService;
        $fromActiveCollision = $service->createUser([
            'first_name' => 'Karyawan',
            'last_name' => 'Satu',
            'email' => 'ACTIVE@example.test',
            'mobile_phone' => '0812-0000-7003',
        ], 'secret-password');
        $fromDeletedCollision = $service->createUser([
            'first_name' => 'Karyawan',
            'last_name' => 'Dua',
            'email' => 'deleted@example.test',
            'mobile_phone' => '0812-0000-7004',
        ], 'secret-password');

        $this->assertNull($fromActiveCollision->email);
        $this->assertNull($fromDeletedCollision->email);
        $this->assertSame(4, User::withTrashed()->count());
    }
}
