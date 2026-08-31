<?php

namespace Tests\Feature;

use App\Filament\Auth\Pages\PhoneLogin;
use App\Models\TicketStatus;
use App\Models\User;
use App\Notifications\ClosedTicketNotification;
use App\Notifications\CommentNotification;
use App\Notifications\NewTicketNotification;
use App\Notifications\TicketStatusChangedNotification;
use App\Services\EmployeeService;
use App\Services\WhatsAppGateway;
use Filament\Facades\Filament;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
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
            'phone' => '62800-0000-0000',
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
        $this->assertMatchesRegularExpression('/Kode verifikasi Helpdesk Anda: \*\d{6}\*/', $this->whatsAppGateway->messages[0]['message']);

        preg_match('/(\d{6})/', $this->whatsAppGateway->messages[0]['message'], $matches);

        $component
            ->set('data.otp', $matches[1])
            ->call('verify')
            ->assertRedirect('/admin');

        $this->assertTrue(Auth::check());
        $this->assertSame($user->id, Auth::id());
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertSame(1, User::count());
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

    public function test_initial_send_is_otp_first_for_unknown_and_active_numbers_without_directory_lookup(): void
    {
        User::query()->create([
            'name' => 'Akun Aktif',
            'email' => null,
            'password' => null,
            'phone' => '6281200009998',
            'is_active' => true,
        ]);
        $employees = $this->mock(EmployeeService::class);
        $employees->shouldNotReceive('findAllByPhone');

        $unknown = Livewire::test(PhoneLogin::class)
            ->fillForm(['phone' => '0812-0000-9999'])
            ->call('send')
            ->assertSet('requiresRegistration', false)
            ->assertSet('awaitingOtp', true)
            ->assertHasNoFormErrors();
        $active = Livewire::test(PhoneLogin::class)
            ->fillForm(['phone' => '0812-0000-9998'])
            ->call('send')
            ->assertSet('requiresRegistration', false)
            ->assertSet('awaitingOtp', true)
            ->assertHasNoFormErrors();

        $this->assertSame($unknown->get('awaitingOtp'), $active->get('awaitingOtp'));
        $this->assertSame($unknown->get('requiresRegistration'), $active->get('requiresRegistration'));
        $this->assertCount(2, $this->whatsAppGateway->messages);
        $this->assertSame('6281200009999', $this->whatsAppGateway->messages[0]['phone']);
        $this->assertSame('6281200009998', $this->whatsAppGateway->messages[1]['phone']);
        $this->assertSame(1, User::count());
    }

    public function test_phone_otp_login_registers_new_user_manually(): void
    {
        $component = Livewire::test(PhoneLogin::class)
            ->fillForm(['phone' => '0812-0000-9999'])
            ->call('send')
            ->assertSet('requiresRegistration', false)
            ->assertSet('awaitingOtp', true);

        $this->assertSame(0, User::count());
        $this->assertCount(1, $this->whatsAppGateway->messages);

        $component
            ->set('data.otp', $this->otpCode())
            ->call('verify')
            ->assertNoRedirect()
            ->assertSet('awaitingOtp', false)
            ->assertSet('requiresRegistration', true);

        $this->assertSame(0, User::count());
        $this->assertCount(1, $this->whatsAppGateway->messages);

        $component
            ->fillForm([
                'phone' => '6281200009999',
                'name' => 'User Baru',
            ])
            ->call('completeRegistration')
            ->assertRedirect('/admin');

        $user = User::where('phone_normalized', '6281200009999')->firstOrFail();
        $this->assertSame('6281200009999', $user->phone);
        $this->assertNull($user->email);
        $this->assertNull($user->password);
        $this->assertNull($user->email_verified_at);
        $this->assertCount(1, $this->whatsAppGateway->messages);
        $this->assertTrue(Auth::check());
        $this->assertSame($user->id, Auth::id());
    }

    public function test_public_registration_state_cannot_bypass_server_side_phone_proof(): void
    {
        Livewire::test(PhoneLogin::class)
            ->set('requiresRegistration', true)
            ->fillForm([
                'phone' => '0812-0000-9988',
                'name' => 'Nama Tanpa Proof',
            ])
            ->call('completeRegistration')
            ->assertHasFormErrors([
                'phone' => 'Bukti verifikasi sudah tidak valid atau kedaluwarsa. Mulai ulang proses login.',
            ])
            ->assertSet('requiresRegistration', false);

        $this->assertSame(0, User::count());
        $this->assertSame([], $this->whatsAppGateway->messages);
        $this->assertFalse(Auth::check());
    }

    public function test_phone_otp_login_auto_registers_user_from_talenta_directory(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'talenta').'.json';
        File::put($tmpFile, json_encode([
            'data' => ['data' => [[
                'first_name' => 'Karyawan',
                'last_name' => 'Talenta',
                'email' => 'karyawan@talenta.co',
                'mobile_phone' => '0812-0000-1234',
                'phone' => '0812-3456-7890',
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

            $this->assertSame(0, User::count());
            $this->assertCount(1, $this->whatsAppGateway->messages);
            $this->assertSame('6281234567890', $this->whatsAppGateway->messages[0]['phone']);

            preg_match('/(\d{6})/', $this->whatsAppGateway->messages[0]['message'], $matches);

            $component
                ->set('data.otp', $matches[1])
                ->call('verify')
                ->assertRedirect('/admin');

            $user = User::where('phone', '6281234567890')->firstOrFail();
            $this->assertSame('Karyawan Talenta', $user->name);
            $this->assertSame('karyawan@talenta.co', $user->email);
            $this->assertSame('6281234567890', $user->phone_normalized);
            $this->assertNull($user->password);
            $this->assertNull($user->email_verified_at);
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
            'phone' => '62800-0000-0001',
            'is_active' => false,
        ]);

        Livewire::test(PhoneLogin::class)
            ->fillForm(['phone' => '0800-0000-0001'])
            ->call('send')
            ->assertSet('awaitingOtp', true)
            ->set('data.otp', $this->otpCode())
            ->call('verify')
            ->assertSet('awaitingOtp', false)
            ->assertHasFormErrors([
                'phone' => 'Nomor HP terdaftar namun akun Anda dinonaktifkan. Hubungi administrator. Mulai ulang proses login.',
            ]);

        $this->assertCount(1, $this->whatsAppGateway->messages);
        $this->assertSame(1, User::count());
    }

    public function test_phone_otp_login_rejects_soft_deleted_formatted_account_without_reprovisioning(): void
    {
        $deleted = User::create([
            'name' => 'Akun Terhapus',
            'email' => 'deleted-phone@example.test',
            'password' => null,
            'phone' => '+62 800-0000-0002',
            'is_active' => true,
        ]);
        $deleted->delete();

        Livewire::test(PhoneLogin::class)
            ->fillForm(['phone' => '0800-0000-0002'])
            ->call('send')
            ->assertSet('awaitingOtp', true)
            ->set('data.otp', $this->otpCode())
            ->call('verify')
            ->assertSet('awaitingOtp', false)
            ->assertHasFormErrors([
                'phone' => 'Nomor HP terdaftar namun akun Anda dinonaktifkan. Hubungi administrator. Mulai ulang proses login.',
            ]);

        $this->assertCount(1, $this->whatsAppGateway->messages);
        $this->assertSame(1, User::withTrashed()->count());
        $this->assertSame(0, User::count());
    }

    public function test_phone_otp_login_rejects_ambiguous_normalized_helpdesk_accounts(): void
    {
        User::create([
            'name' => 'Akun Format Satu',
            'email' => 'format-one@example.test',
            'password' => null,
            'phone' => '6280000000003',
            'is_active' => true,
        ]);
        User::create([
            'name' => 'Akun Format Dua',
            'email' => 'format-two@example.test',
            'password' => null,
            'phone' => '0800-0000-0003',
            'is_active' => true,
        ]);

        Livewire::test(PhoneLogin::class)
            ->fillForm(['phone' => '+62 800 0000 0003'])
            ->call('send')
            ->assertSet('awaitingOtp', true)
            ->set('data.otp', $this->otpCode())
            ->call('verify')
            ->assertSet('awaitingOtp', false)
            ->assertHasFormErrors([
                'phone' => 'Nomor HP cocok dengan lebih dari satu akun. Hubungi administrator untuk merapikan data. Mulai ulang proses login.',
            ]);

        $this->assertCount(1, $this->whatsAppGateway->messages);
        $this->assertSame(2, User::count());
    }

    public function test_phone_otp_login_rejects_ambiguous_talenta_phone_instead_of_opening_manual_registration(): void
    {
        $temporary = tempnam(sys_get_temp_dir(), 'talenta-ambiguous-login-');
        File::put($temporary, json_encode([
            'data' => ['data' => [
                [
                    'id_employee' => 'EMP-ONE',
                    'first_name' => 'Karyawan',
                    'last_name' => 'Satu',
                    'mobile_phone' => '0800-0000-0004',
                ],
                [
                    'id_employee' => 'EMP-TWO',
                    'first_name' => 'Karyawan',
                    'last_name' => 'Dua',
                    'mobile_phone' => '6280000000004',
                ],
            ]],
        ], JSON_THROW_ON_ERROR));
        config()->set('services.talenta.employee_file', $temporary);
        Cache::flush();

        try {
            $component = Livewire::test(PhoneLogin::class)
                ->fillForm(['phone' => '0800-0000-0004'])
                ->call('send')
                ->assertSet('requiresRegistration', false)
                ->assertSet('awaitingOtp', true);

            $component
                ->set('data.otp', $this->otpCode())
                ->call('verify')
                ->assertSet('awaitingOtp', false)
                ->assertHasFormErrors([
                    'phone' => 'Nomor HP cocok dengan lebih dari satu data karyawan Talenta. Hubungi administrator untuk merapikan data. Mulai ulang proses login.',
                ]);

            $this->assertCount(1, $this->whatsAppGateway->messages);
            $this->assertSame(0, User::count());
        } finally {
            File::delete($temporary);
        }
    }

    public function test_manual_registration_wrong_otp_never_creates_an_active_account(): void
    {
        $component = Livewire::test(PhoneLogin::class)
            ->fillForm(['phone' => '0812-0000-8888'])
            ->call('send')
            ->assertSet('awaitingOtp', true);

        $this->assertSame(0, User::count());
        $actualCode = $this->otpCode();
        $component
            ->set('data.otp', $actualCode === '000000' ? '999999' : '000000')
            ->call('verify')
            ->assertHasFormErrors(['otp' => 'Kode OTP tidak valid atau sudah kedaluwarsa.']);

        $this->assertSame(0, User::count());
        $this->assertFalse(Auth::validate([
            'email' => 'pending@example.test',
            'password' => 'attacker-password',
        ]));
    }

    public function test_manual_registration_delivery_failure_leaves_no_user(): void
    {
        $this->whatsAppGateway->deliver = false;

        Livewire::test(PhoneLogin::class)
            ->fillForm(['phone' => '0812-0000-7777'])
            ->call('send')
            ->assertHasFormErrors(['phone' => 'OTP belum bisa dikirim ke WhatsApp. Coba lagi sebentar lagi.']);

        $this->assertSame(0, User::count());
        $this->assertFalse(Auth::validate([
            'email' => 'delivery-failed@example.test',
            'password' => 'secret123',
        ]));
    }

    public function test_manual_registration_cache_loss_leaves_no_user(): void
    {
        $component = Livewire::test(PhoneLogin::class)
            ->fillForm(['phone' => '0812-0000-6666'])
            ->call('send')
            ->assertSet('awaitingOtp', true);

        $code = $this->otpCode();
        Cache::flush();
        $component
            ->set('data.otp', $code)
            ->call('verify')
            ->assertHasFormErrors(['otp' => 'Kode OTP tidak valid atau sudah kedaluwarsa.']);

        $this->assertSame(0, User::count());
    }

    public function test_verified_manual_registration_is_rejected_if_talenta_changes_before_name_is_saved(): void
    {
        $emptyDirectory = tempnam(sys_get_temp_dir(), 'talenta-empty-login-');
        $changedDirectory = tempnam(sys_get_temp_dir(), 'talenta-changed-login-');
        File::put($emptyDirectory, json_encode(['data' => ['data' => []]], JSON_THROW_ON_ERROR));
        File::put($changedDirectory, json_encode([
            'data' => ['data' => [[
                'id_employee' => 'EMP-CHANGED',
                'first_name' => 'Data',
                'last_name' => 'Talenta Baru',
                'email' => 'changed-talenta@example.test',
                'mobile_phone' => '0812-0000-6111',
            ]]],
        ], JSON_THROW_ON_ERROR));
        config()->set('services.talenta.employee_file', $emptyDirectory);

        try {
            $component = Livewire::test(PhoneLogin::class)
                ->fillForm(['phone' => '0812-0000-6111'])
                ->call('send')
                ->assertSet('awaitingOtp', true);

            $component
                ->set('data.otp', $this->otpCode())
                ->call('verify')
                ->assertSet('awaitingOtp', false)
                ->assertSet('requiresRegistration', true);
            $this->assertSame(0, User::count());

            config()->set('services.talenta.employee_file', $changedDirectory);
            $component
                ->fillForm([
                    'phone' => '6281200006111',
                    'name' => 'Manual Pending',
                ])
                ->call('completeRegistration')
                ->assertSet('requiresRegistration', false)
                ->assertHasFormErrors(['phone']);

            $this->assertSame(0, User::count());
            $this->assertFalse(Auth::check());
        } finally {
            File::delete([$emptyDirectory, $changedDirectory]);
        }
    }

    public function test_tampered_phone_cannot_consume_otp_for_another_number(): void
    {
        $component = Livewire::test(PhoneLogin::class)
            ->fillForm(['phone' => '0812-0000-5555'])
            ->call('send')
            ->assertSet('awaitingOtp', true);

        $component
            ->set('data.phone', '0812-0000-4444')
            ->set('data.otp', $this->otpCode())
            ->call('verify')
            ->assertSet('awaitingOtp', false)
            ->assertHasFormErrors(['phone' => 'Identitas nomor OTP tidak cocok. Mulai ulang proses login.']);

        $this->assertSame(0, User::count());
    }

    public function test_verified_manual_registration_requires_its_cached_server_side_proof(): void
    {
        $component = Livewire::test(PhoneLogin::class)
            ->fillForm(['phone' => '0812-0000-5554'])
            ->call('send')
            ->assertSet('awaitingOtp', true);

        $component
            ->set('data.otp', $this->otpCode())
            ->call('verify')
            ->assertSet('awaitingOtp', false)
            ->assertSet('requiresRegistration', true);

        $this->assertSame(0, User::count());
        Cache::flush();

        $component
            ->fillForm([
                'phone' => '6281200005554',
                'name' => 'Nama Setelah Proof Hilang',
            ])
            ->call('completeRegistration')
            ->assertSet('requiresRegistration', false)
            ->assertHasFormErrors([
                'phone' => 'Bukti verifikasi sudah tidak valid atau kedaluwarsa. Mulai ulang proses login.',
            ]);

        $this->assertSame(0, User::count());
        $this->assertFalse(Auth::check());
    }

    public function test_verified_manual_registration_proof_is_bound_to_its_phone(): void
    {
        $component = Livewire::test(PhoneLogin::class)
            ->fillForm(['phone' => '0812-0000-5553'])
            ->call('send')
            ->assertSet('awaitingOtp', true);

        $component
            ->set('data.otp', $this->otpCode())
            ->call('verify')
            ->assertSet('awaitingOtp', false)
            ->assertSet('requiresRegistration', true);

        $component
            ->fillForm([
                'phone' => '0812-0000-5552',
                'name' => 'Nama Untuk Nomor Lain',
            ])
            ->call('completeRegistration')
            ->assertSet('requiresRegistration', false)
            ->assertHasFormErrors([
                'phone' => 'Bukti verifikasi sudah tidak valid atau kedaluwarsa. Mulai ulang proses login.',
            ]);

        $this->assertSame(0, User::count());
        $this->assertFalse(Auth::check());
    }

    public function test_unverified_email_is_not_a_login_or_mail_notification_channel(): void
    {
        $user = User::query()->create([
            'name' => 'Email Belum Terverifikasi',
            'email' => 'unverified@example.test',
            'password' => Hash::make('secret123'),
            'phone' => '6281200003333',
            'is_active' => true,
        ]);

        $credentials = ['email' => 'unverified@example.test', 'password' => 'secret123'];
        $notifications = [
            new NewTicketNotification((object) []),
            new CommentNotification((object) ['ticket' => (object) []]),
            new ClosedTicketNotification((object) []),
            new TicketStatusChangedNotification((object) [], TicketStatus::IN_PROGRESS),
        ];

        $this->assertFalse(Auth::validate($credentials));
        foreach ($notifications as $notification) {
            $this->assertNotContains('mail', $notification->via($user));
        }

        $user->forceFill([
            'email_verified_at' => now(),
            'email_verified_via' => 'admin',
        ])->save();

        $this->assertTrue(Auth::validate($credentials));
        foreach ($notifications as $notification) {
            $this->assertContains('mail', $notification->via($user->fresh()));
        }

        $user->update(['email' => 'changed@example.test']);
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertNull($user->fresh()->email_verified_via);
        $this->assertFalse(Auth::validate([
            'email' => 'changed@example.test',
            'password' => 'secret123',
        ]));
    }

    public function test_factory_accounts_have_explicit_email_verification_provenance(): void
    {
        $verified = User::factory()->create();
        $unverified = User::factory()->unverified()->create();

        $this->assertSame('admin', $verified->email_verified_via);
        $this->assertTrue($verified->canUseVerifiedEmail());
        $this->assertNull($unverified->email_verified_at);
        $this->assertNull($unverified->email_verified_via);
        $this->assertFalse($unverified->canUseVerifiedEmail());
    }

    public function test_panel_access_requires_a_matching_phone_session_or_trusted_email(): void
    {
        $panel = Filament::getPanel('admin');
        $legacyUser = User::query()->create([
            'name' => 'Legacy Email User',
            'email' => 'legacy-panel@example.test',
            'email_verified_at' => now(),
            'email_verified_via' => 'legacy_review_required',
            'password' => Hash::make('secret123'),
            'phone' => '6281200004001',
            'is_active' => true,
        ]);
        $otherUser = User::query()->create([
            'name' => 'Other Phone User',
            'email' => null,
            'password' => null,
            'phone' => '6281200004002',
            'is_active' => true,
        ]);

        session()->forget('helpdesk_phone_verified_user_id');
        $this->assertFalse($legacyUser->canAccessPanel($panel));

        session()->put('helpdesk_phone_verified_user_id', $otherUser->id);
        $this->assertFalse($legacyUser->canAccessPanel($panel));

        session()->put('helpdesk_phone_verified_user_id', $legacyUser->id);
        $this->assertTrue($legacyUser->canAccessPanel($panel));

        $legacyUser->update(['is_active' => false]);
        $this->assertFalse($legacyUser->canAccessPanel($panel));

        session()->forget('helpdesk_phone_verified_user_id');
        $trustedUser = User::query()->create([
            'name' => 'Trusted Admin',
            'email' => 'trusted-panel@example.test',
            'email_verified_at' => now(),
            'email_verified_via' => 'admin',
            'password' => Hash::make('secret123'),
            'phone' => null,
            'is_active' => true,
        ]);

        $this->assertTrue($trustedUser->canAccessPanel($panel));
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
            $table->string('email_verified_via')->nullable();
            $table->string('password')->nullable();
            $table->string('remember_token')->nullable();
            $table->string('identity')->nullable();
            $table->string('phone', 20)->nullable()->unique();
            $table->string('phone_normalized', 20)->nullable()->index();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    private function otpCode(int $messageIndex = 0): string
    {
        $message = (string) ($this->whatsAppGateway->messages[$messageIndex]['message'] ?? '');
        preg_match('/\b([0-9]{6})\b/', $message, $matches);
        $this->assertArrayHasKey(1, $matches, 'Fake WhatsApp gateway did not receive an OTP.');

        return $matches[1];
    }
}

class FakeWhatsAppGateway extends WhatsAppGateway
{
    public array $messages = [];

    public bool $deliver = true;

    public function send($phoneNumber, string $message): bool
    {
        $this->messages[] = [
            'phone' => (string) $phoneNumber,
            'message' => $message,
        ];

        return $this->deliver;
    }
}
