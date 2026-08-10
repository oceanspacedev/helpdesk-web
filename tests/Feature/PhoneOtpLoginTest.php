<?php

namespace Tests\Feature;

use App\Filament\Auth\Pages\PhoneLogin;
use App\Models\User;
use App\Services\WhatsAppGateway;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class PhoneOtpLoginTest extends TestCase
{
    private FakeWhatsAppGateway $whatsAppGateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTestSchema();
        Cache::flush();
        $this->whatsAppGateway = new FakeWhatsAppGateway;
        $this->app->instance(WhatsAppGateway::class, $this->whatsAppGateway);
        config()->set('services.phone_otp_login.ttl_minutes', 5);
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
    }

    public function test_user_can_login_with_phone_otp_sent_to_whatsapp(): void
    {
        $user = User::create([
            'name' => 'Budi Outlet Depok',
            'email' => null,
            'password' => null,
            'phone' => '6280000000000',
            'is_active' => true,
        ]);

        $component = Livewire::test(PhoneLogin::class)
            ->fillForm(['phone' => '0800-0000-0000'])
            ->call('send')
            ->assertNoRedirect()
            ->assertSet('awaitingOtp', true)
            ->assertSet('data.phone', '6280000000000')
            ->assertSeeHtml('wire:submit="verify"');

        $this->assertCount(1, $this->whatsAppGateway->messages);
        $this->assertSame('6280000000000', $this->whatsAppGateway->messages[0]['phone']);
        $this->assertMatchesRegularExpression('/Kode OTP Helpdesk Anda: \*\d{6}\*/', $this->whatsAppGateway->messages[0]['message']);

        preg_match('/(\d{6})/', $this->whatsAppGateway->messages[0]['message'], $matches);

        $component
            ->set('data.otp', $matches[1])
            ->call('verify')
            ->assertRedirect('/admin');

        $this->assertTrue(Auth::check());
        $this->assertSame($user->id, Auth::id());
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_phone_login_does_not_expose_a_separate_web_verification_form(): void
    {
        $this->assertNull(app('router')->getRoutes()->getByName('phone-login.verify'));
        $this->assertNull(app('router')->getRoutes()->getByName('phone-login.verify.submit'));
    }

    public function test_phone_login_accepts_a_real_livewire_http_update(): void
    {
        $html = $this->get('/phone-login')->assertOk()->getContent();

        preg_match('/wire:snapshot="([^"]+)"/', $html, $matches);
        $snapshot = html_entity_decode($matches[1] ?? '', ENT_QUOTES | ENT_HTML5);

        $this->postJson('/livewire/update', [
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => ['data.phone' => '081200009999'],
                'calls' => [[
                    'path' => '',
                    'method' => 'send',
                    'params' => [],
                ]],
            ]],
        ])->assertOk();
    }

    public function test_phone_login_route_does_not_run_web_session_and_csrf_middleware_twice(): void
    {
        $route = app('router')->getRoutes()->getByName('phone-login');
        $middleware = app('router')->gatherRouteMiddleware($route);

        $this->assertSame(1, collect($middleware)->filter(
            fn (string $class): bool => is_a($class, EncryptCookies::class, true),
        )->count());
        $this->assertSame(1, collect($middleware)->filter(
            fn (string $class): bool => $class === StartSession::class,
        )->count());
        $this->assertSame(1, collect($middleware)->filter(
            fn (string $class): bool => is_a($class, VerifyCsrfToken::class, true),
        )->count());
    }

    public function test_phone_otp_login_triggers_registration_for_unknown_numbers(): void
    {
        Livewire::test(PhoneLogin::class)
            ->fillForm(['phone' => '0812-0000-9999'])
            ->call('send')
            ->assertSet('requiresRegistration', true)
            ->assertSet('awaitingOtp', false)
            ->assertHasNoFormErrors();

        $this->assertSame([], $this->whatsAppGateway->messages);
        $this->assertSame(0, User::count());
    }

    public function test_phone_otp_login_registers_new_user_manually(): void
    {
        $component = Livewire::test(PhoneLogin::class)
            ->fillForm(['phone' => '0812-0000-9999'])
            ->call('send')
            ->assertSet('requiresRegistration', true);

        // Isi form registrasi pada komponen yang SAMA supaya state persists.
        $component
            ->fillForm([
                'phone' => '0812-0000-9999',
                'name' => 'User Baru',
                'email' => 'baru@example.com',
                'password' => 'secret123',
            ])
            ->call('send')
            ->assertSet('awaitingOtp', true);

        $user = User::where('email', 'baru@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('6281200009999', $user->phone);
        $this->assertCount(1, $this->whatsAppGateway->messages);

        preg_match('/(\d{6})/', $this->whatsAppGateway->messages[0]['message'], $matches);

        $component
            ->set('data.otp', $matches[1])
            ->call('verify')
            ->assertRedirect('/admin');

        $this->assertTrue(Auth::check());
        $this->assertSame($user->id, Auth::id());
    }

    public function test_phone_otp_login_auto_registers_user_from_talenta_directory(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'talenta').'.json';
        File::put($tmpFile, json_encode([
            'data' => ['data' => [[
                'first_name' => 'Karyawan',
                'last_name' => 'Talenta',
                'email' => 'karyawan@talenta.co',
                'mobile_phone' => '0812-3456-7890',
            ]]],
        ]));
        config()->set('services.talenta.employee_file', $tmpFile);
        Cache::flush();

        try {
            $component = Livewire::test(PhoneLogin::class)
                ->fillForm(['phone' => '0812-3456-7890'])
                ->call('send')
                ->assertSet('awaitingOtp', true)
                ->assertSet('requiresRegistration', false);

            $user = User::where('phone', '6281234567890')->first();
            $this->assertNotNull($user);
            $this->assertSame('Karyawan Talenta', $user->name);
            $this->assertSame('karyawan@talenta.co', $user->email);
            $this->assertNotNull($user->password);

            $this->assertCount(1, $this->whatsAppGateway->messages);
            $this->assertSame('6281234567890', $this->whatsAppGateway->messages[0]['phone']);

            preg_match('/(\d{6})/', $this->whatsAppGateway->messages[0]['message'], $matches);

            $component
                ->set('data.otp', $matches[1])
                ->call('verify')
                ->assertRedirect('/admin');

            $this->assertTrue(Auth::check());
            $this->assertSame($user->id, Auth::id());
        } finally {
            File::delete($tmpFile);
        }
    }

    public function test_phone_otp_login_rejects_inactive_account_instead_of_creating_duplicate(): void
    {
        User::create([
            'name' => 'Akun Nonaktif',
            'email' => null,
            'password' => null,
            'phone' => '6280000000001',
            'is_active' => false,
        ]);

        Livewire::test(PhoneLogin::class)
            ->fillForm(['phone' => '0800-0000-0001'])
            ->call('send')
            ->assertHasFormErrors(['phone' => 'Nomor HP terdaftar namun akun Anda dinonaktifkan. Hubungi administrator.']);

        $this->assertSame([], $this->whatsAppGateway->messages);
        $this->assertSame(1, User::count());
    }

    public function test_phone_otp_login_rate_limits_send(): void
    {
        User::create([
            'name' => 'Budi Outlet Depok',
            'email' => null,
            'password' => null,
            'phone' => '6280000000000',
            'is_active' => true,
        ]);

        // Kirim 3 kali untuk mencapai batas.
        for ($i = 0; $i < 3; $i++) {
            Livewire::test(PhoneLogin::class)
                ->fillForm(['phone' => '0800-0000-0000'])
                ->call('send')
                ->assertSet('awaitingOtp', true);
        }

        // Percobaan ke-4 harus ditolak oleh rate limiter.
        Livewire::test(PhoneLogin::class)
            ->fillForm(['phone' => '0800-0000-0000'])
            ->call('send')
            ->assertHasFormErrors(['phone']);

        // Tidak ada OTP tambahan yang terkirim.
        $this->assertCount(3, $this->whatsAppGateway->messages);
    }

    public function test_phone_otp_login_rejects_wrong_codes(): void
    {
        User::create([
            'name' => 'Budi Outlet Depok',
            'email' => null,
            'password' => null,
            'phone' => '6280000000000',
            'is_active' => true,
        ]);

        Livewire::test(PhoneLogin::class)
            ->fillForm(['phone' => '0800-0000-0000'])
            ->call('send')
            ->set('data.otp', '000000')
            ->call('verify')
            ->assertHasFormErrors(['otp' => 'Kode OTP tidak valid atau sudah kedaluwarsa.']);

        $this->assertFalse(Auth::check());
    }

    private function createTestSchema(): void
    {
        foreach (['activity_log', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('activity_log', function (Blueprint $table): void {
            $table->id();
            $table->string('log_name')->nullable();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->nullableMorphs('causer', 'causer');
            $table->string('event')->nullable();
            $table->json('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->string('remember_token')->nullable();
            $table->string('identity')->nullable();
            $table->string('phone', 20)->nullable()->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }
}

class FakeWhatsAppGateway extends WhatsAppGateway
{
    public array $messages = [];

    public function send($phoneNumber, string $message): bool
    {
        $this->messages[] = [
            'phone' => (string) $phoneNumber,
            'message' => $message,
        ];

        return true;
    }
}
