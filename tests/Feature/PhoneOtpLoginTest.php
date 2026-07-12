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
            ->assertSet('data.phone', '6280000000000');

        $this->assertCount(1, $this->whatsAppGateway->messages);
        $this->assertSame('6280000000000', $this->whatsAppGateway->messages[0]['phone']);
        $this->assertMatchesRegularExpression('/Kode OTP Helpdesk Anda: \d{6}/', $this->whatsAppGateway->messages[0]['message']);

        preg_match('/(\d{6})/', $this->whatsAppGateway->messages[0]['message'], $matches);

        $component
            ->set('data.otp', $matches[1])
            ->call('verify')
            ->assertRedirect('/admin');

        $this->assertTrue(Auth::check());
        $this->assertTrue(Auth::user()->is($user));
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_phone_login_does_not_expose_a_separate_web_verification_form(): void
    {
        $this->assertNull(app('router')->getRoutes()->getByName('phone-login.verify'));
        $this->assertNull(app('router')->getRoutes()->getByName('phone-login.verify.submit'));
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

    public function test_phone_otp_login_rejects_unknown_phone_numbers(): void
    {
        Livewire::test(PhoneLogin::class)
            ->fillForm(['phone' => '0812-0000-9999'])
            ->call('send')
            ->assertHasFormErrors(['phone' => 'Nomor HP belum terdaftar atau tidak aktif.']);

        $this->assertSame([], $this->whatsAppGateway->messages);
        $this->assertSame(0, User::count());
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
            ->assertRedirect(route('phone-login.verify'));

        $this->post('/phone-login/verify', [
            'phone' => '6280000000000',
            'otp' => '000000',
        ])
            ->assertSessionHasErrors('otp');

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
