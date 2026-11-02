<?php

namespace Tests\Feature;

use App\Filament\Auth\Pages\EmailLogin;
use App\Mail\EmailLoginMail;
use App\Models\EmailOtp;
use App\Models\User;
use App\Services\EmailOtpService;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Livewire\Mechanisms\ComponentRegistry;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class EmailOtpLoginTest extends TestCase
{
    private int $fillerCount = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTestSchema();
        Cache::flush();

        config()->set('services.whatsapp_gateway.url');
        config()->set('services.whatsapp_gateway.token');
        config()->set('services.email_login.expires_in', 600);
        config()->set('mail.default', 'smtp');
        config()->set('mail.mailers.smtp.transport', 'smtp');
        config()->set('mail.from.address', 'helpdesk@example.test');

        Mail::fake();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
    }

    public function test_login_page_offers_email_and_whatsapp_but_not_google(): void
    {
        config()->set('services.whatsapp_gateway.url', 'https://gateway.example.test');
        config()->set('services.whatsapp_gateway.token', 'test-token');

        $this->get('/admin/login')
            ->assertOk()
            ->assertSeeInOrder(['Atau masuk dengan', 'WhatsApp', 'Email'])
            ->assertSeeHtml(route('phone-login'))
            ->assertSeeHtml(route('email-login'))
            ->assertDontSee('Google')
            ->assertDontSeeHtml('/auth/google');
    }

    public function test_login_page_hides_email_when_mailer_only_logs_or_stores_messages(): void
    {
        foreach (['log', 'array'] as $mailer) {
            config()->set('mail.default', $mailer);

            $this->get('/admin/login')
                ->assertOk()
                ->assertDontSee('Atau masuk dengan')
                ->assertDontSeeHtml(route('email-login'));
        }
    }

    public function test_google_login_routes_are_removed(): void
    {
        $this->get('/auth/google')->assertNotFound();
        $this->get('/auth/google/callback?code=abc')->assertNotFound();
    }

    public function test_email_login_routes_and_livewire_component_are_registered(): void
    {
        $routes = app('router')->getRoutes();

        foreach (['email-login', 'email-login.link'] as $name) {
            $route = $routes->getByName($name);
            $this->assertNotNull($route);

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

        $this->assertSame(EmailLogin::class, app(ComponentRegistry::class)->getClass('email-login'));
    }

    public function test_email_login_accepts_a_real_livewire_http_update(): void
    {
        $this->createUser();

        $html = $this->get('/email-login')->assertOk()->getContent();

        preg_match('/wire:snapshot="([^"]+)"/', $html, $matches);
        $snapshot = html_entity_decode($matches[1] ?? '', ENT_QUOTES | ENT_HTML5);

        $this->postJson('/livewire/update', [
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => ['data.email' => 'staf@example.test'],
                'calls' => [[
                    'path' => '',
                    'method' => 'send',
                    'params' => [],
                ]],
            ]],
        ])->assertOk();

        Mail::assertSent(EmailLoginMail::class, 1);
    }

    public function test_correct_code_logs_in_and_verifies_the_email(): void
    {
        $user = $this->createUser(['email' => 'Staf@Example.test']);

        $component = Livewire::test(EmailLogin::class)
            ->assertSee('Kirim Kode ke Email')
            ->fillForm(['email' => 'STAF@example.TEST'])
            ->call('send')
            ->assertHasNoFormErrors()
            ->assertNoRedirect()
            ->assertSet('awaitingOtp', true)
            ->assertSet('data.email', 'staf@example.test')
            ->assertSee('Verifikasi dan Masuk');

        $mail = $this->sentLoginMail('staf@example.test');
        $this->assertMatchesRegularExpression('/^\d{6}$/D', $mail->otp);
        $this->assertSame('Staf Helpdesk', $mail->name);
        $this->assertSame(10, $mail->expiresInMinutes);

        $record = EmailOtp::query()->where('user_id', $user->id)->latest('id')->firstOrFail();
        $this->assertSame(EmailOtp::PURPOSE_LOGIN, $record->purpose);
        $this->assertNotSame($mail->otp, $record->otp_hash);
        $this->assertTrue(Hash::check($mail->otp, $record->otp_hash));
        $this->assertSame(hash('sha256', $this->tokenFrom($mail)), $record->token_hash);

        $component
            ->set('data.otp', $mail->otp)
            ->call('verify')
            ->assertHasNoFormErrors()
            ->assertRedirect('/admin');

        $this->assertSame($user->id, Auth::id());
        $this->assertNotNull($record->fresh()->verified_at);

        // Kode dari kotak masuk membuktikan kepemilikan email, jadi akun bisa membuka panel di sesi berikutnya.
        $user->refresh();
        $this->assertSame('email_link', $user->email_verified_via);
        $this->assertTrue($user->canUseVerifiedEmail());
    }

    public function test_login_keeps_existing_trusted_email_provenance(): void
    {
        $user = $this->createUser([
            'email_verified_at' => now()->subYear(),
            'email_verified_via' => 'admin',
        ]);

        $this->loginWithCode($this->requestCode('staf@example.test'));

        $this->assertSame($user->id, Auth::id());
        $this->assertSame('admin', $user->fresh()->email_verified_via);
    }

    public function test_wrong_code_is_rejected_and_exhausts_after_max_attempts(): void
    {
        $user = $this->createUser();
        $mail = $this->requestCode('staf@example.test');
        $wrongOtp = $mail->otp === '000000' ? '111111' : '000000';

        $component = $this->awaitingCodeFor('staf@example.test');

        for ($attempt = 1; $attempt <= EmailOtpService::MAX_ATTEMPTS; $attempt++) {
            $component->set('data.otp', $wrongOtp)
                ->call('verify')
                ->assertHasFormErrors(['otp' => 'Kode tidak valid atau sudah kedaluwarsa.'])
                ->assertNoRedirect();
        }

        $this->assertSame(EmailOtpService::MAX_ATTEMPTS, EmailOtp::query()->firstOrFail()->attempt_count);

        $component->set('data.otp', $mail->otp)->call('verify')->assertHasFormErrors(['otp']);

        // Kode yang habis percobaannya ikut mematikan magic link di record yang sama.
        Livewire::test(EmailLogin::class, ['token' => $this->tokenFrom($mail)])
            ->call('consumeMagicLink')
            ->assertHasErrors(['data.email']);

        $this->assertFalse(Auth::check());
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_expired_code_is_rejected(): void
    {
        $this->createUser();
        $mail = $this->requestCode('staf@example.test');

        $this->travel(601)->seconds();

        $this->awaitingCodeFor('staf@example.test')
            ->set('data.otp', $mail->otp)
            ->call('verify')
            ->assertHasFormErrors(['otp' => 'Kode tidak valid atau sudah kedaluwarsa.'])
            ->assertNoRedirect();

        $this->assertFalse(Auth::check());
        $this->assertNull(EmailOtp::query()->firstOrFail()->verified_at);
    }

    public function test_used_code_and_its_magic_link_cannot_be_reused(): void
    {
        $user = $this->createUser();
        $mail = $this->requestCode('staf@example.test');

        $this->loginWithCode($mail);
        $this->assertSame($user->id, Auth::id());

        Auth::logout();

        $this->awaitingCodeFor('staf@example.test')
            ->set('data.otp', $mail->otp)
            ->call('verify')
            ->assertHasFormErrors(['otp' => 'Kode tidak valid atau sudah kedaluwarsa.'])
            ->assertNoRedirect();

        Livewire::test(EmailLogin::class, ['token' => $this->tokenFrom($mail)])
            ->call('consumeMagicLink')
            ->assertHasErrors(['data.email'])
            ->assertNoRedirect();

        $this->assertFalse(Auth::check());
    }

    public function test_valid_magic_link_logs_in_without_typing_the_code(): void
    {
        $user = $this->createUser();
        $mail = $this->requestCode('staf@example.test');
        $token = $this->tokenFrom($mail);

        $this->assertSame(route('email-login.link', ['token' => $token]), $mail->loginUrl);

        Livewire::test(EmailLogin::class, ['token' => $token])
            ->assertSee('Memproses link masuk')
            ->assertSeeHtml('wire:init="consumeMagicLink"')
            ->call('consumeMagicLink')
            ->assertHasNoErrors()
            ->assertRedirect('/admin');

        $this->assertSame($user->id, Auth::id());
        $this->assertSame('email_link', $user->fresh()->email_verified_via);

        // Link dan kode berbagi satu kali pakai.
        Auth::logout();
        $this->awaitingCodeFor('staf@example.test')
            ->set('data.otp', $mail->otp)
            ->call('verify')
            ->assertHasFormErrors(['otp']);
    }

    public function test_opening_the_magic_link_url_does_not_consume_it(): void
    {
        $this->createUser();
        $token = $this->tokenFrom($this->requestCode('staf@example.test'));

        // Pemindai link di email hanya melakukan GET; token baru dipakai saat browser menjalankan wire:init.
        $this->get(route('email-login.link', ['token' => $token]))
            ->assertOk()
            ->assertSee('Memproses link masuk');

        $this->assertFalse(Auth::check());
        $this->assertNull(EmailOtp::query()->firstOrFail()->verified_at);
    }

    public function test_invalid_unknown_or_expired_magic_link_is_rejected(): void
    {
        $this->createUser();
        $token = $this->tokenFrom($this->requestCode('staf@example.test'));

        $this->get('/email-login/link/'.Str::random(63))->assertNotFound();
        $this->get('/email-login/link/'.Str::random(64).'!')->assertNotFound();

        Livewire::test(EmailLogin::class, ['token' => Str::random(64)])
            ->call('consumeMagicLink')
            ->assertHasErrors(['data.email' => 'Link masuk tidak valid atau sudah kedaluwarsa. Minta kode baru di bawah.'])
            ->assertNoRedirect();

        $this->travel(601)->seconds();

        Livewire::test(EmailLogin::class, ['token' => $token])
            ->call('consumeMagicLink')
            ->assertHasErrors(['data.email'])
            ->assertNoRedirect();

        $this->assertFalse(Auth::check());
    }

    public function test_requesting_a_new_code_invalidates_the_previous_code_and_link(): void
    {
        $this->createUser();
        $first = $this->requestCode('staf@example.test');
        $second = $this->requestCode('staf@example.test');

        $this->assertNotSame($first->loginUrl, $second->loginUrl);

        Livewire::test(EmailLogin::class, ['token' => $this->tokenFrom($first)])
            ->call('consumeMagicLink')
            ->assertHasErrors(['data.email']);

        if ($first->otp !== $second->otp) {
            $this->awaitingCodeFor('staf@example.test')
                ->set('data.otp', $first->otp)
                ->call('verify')
                ->assertHasFormErrors(['otp']);
        }

        Livewire::test(EmailLogin::class, ['token' => $this->tokenFrom($second)])
            ->call('consumeMagicLink')
            ->assertRedirect('/admin');
    }

    public function test_changing_the_account_email_invalidates_pending_codes(): void
    {
        $user = $this->createUser();
        $mail = $this->requestCode('staf@example.test');

        $user->update(['email' => 'alamat.baru@example.test']);

        Livewire::test(EmailLogin::class, ['token' => $this->tokenFrom($mail)])
            ->call('consumeMagicLink')
            ->assertHasErrors(['data.email']);

        $this->assertFalse(Auth::check());
    }

    public function test_send_requests_are_rate_limited_per_email(): void
    {
        $this->createUser();

        for ($request = 1; $request <= 3; $request++) {
            $this->requestCode('staf@example.test');
        }

        Livewire::test(EmailLogin::class)
            ->fillForm(['email' => 'STAF@example.test'])
            ->call('send')
            ->assertHasFormErrors(['email' => 'Terlalu banyak permintaan kode. Silakan coba lagi nanti.'])
            ->assertSet('awaitingOtp', false);

        Mail::assertSent(EmailLoginMail::class, 3);
    }

    public function test_send_requests_are_rate_limited_per_ip(): void
    {
        for ($request = 1; $request <= 20; $request++) {
            Livewire::test(EmailLogin::class)
                ->fillForm(['email' => "tidak.dikenal.{$request}@example.test"])
                ->call('send')
                ->assertHasNoFormErrors();
        }

        $this->createUser();

        Livewire::test(EmailLogin::class)
            ->fillForm(['email' => 'staf@example.test'])
            ->call('send')
            ->assertHasFormErrors(['email' => 'Terlalu banyak permintaan kode. Silakan coba lagi nanti.']);

        Mail::assertNothingSent();
    }

    public function test_verify_attempts_are_rate_limited_per_email(): void
    {
        $this->createUser();
        $mail = $this->requestCode('staf@example.test');
        $component = $this->awaitingCodeFor('staf@example.test');

        for ($attempt = 1; $attempt <= 15; $attempt++) {
            $component->set('data.otp', '000000')->call('verify');
        }

        $component->set('data.otp', $mail->otp)
            ->call('verify')
            ->assertHasFormErrors(['otp' => 'Terlalu banyak percobaan kode. Silakan coba lagi nanti.'])
            ->assertNoRedirect();

        $this->assertFalse(Auth::check());
    }

    public function test_magic_link_attempts_are_rate_limited_per_ip(): void
    {
        $this->createUser();
        $token = $this->tokenFrom($this->requestCode('staf@example.test'));

        for ($attempt = 1; $attempt <= 20; $attempt++) {
            Livewire::test(EmailLogin::class, ['token' => Str::random(64)])
                ->call('consumeMagicLink')
                ->assertHasErrors(['data.email']);
        }

        Livewire::test(EmailLogin::class, ['token' => $token])
            ->call('consumeMagicLink')
            ->assertHasErrors(['data.email' => 'Terlalu banyak percobaan masuk. Coba lagi sebentar lagi.'])
            ->assertNoRedirect();

        $this->assertFalse(Auth::check());
        $this->assertNull(EmailOtp::query()->firstOrFail()->verified_at);
    }

    public function test_inactive_account_gets_no_code_and_cannot_log_in(): void
    {
        $this->createUser(['is_active' => false]);

        // Respons sama dengan email terdaftar agar status akun tidak bocor, tetapi tidak ada email yang dikirim.
        Livewire::test(EmailLogin::class)
            ->fillForm(['email' => 'staf@example.test'])
            ->call('send')
            ->assertHasNoFormErrors()
            ->assertSet('awaitingOtp', true)
            ->set('data.otp', '123456')
            ->call('verify')
            ->assertHasFormErrors(['otp' => 'Kode tidak valid atau sudah kedaluwarsa.']);

        Mail::assertNothingSent();
        $this->assertSame(0, EmailOtp::query()->count());
        $this->assertFalse(Auth::check());
    }

    public function test_account_deactivated_after_the_code_was_sent_cannot_log_in(): void
    {
        $user = $this->createUser();
        $mail = $this->requestCode('staf@example.test');

        $user->update(['is_active' => false]);

        $this->awaitingCodeFor('staf@example.test')
            ->set('data.otp', $mail->otp)
            ->call('verify')
            ->assertHasFormErrors(['otp' => 'Kode tidak valid atau sudah kedaluwarsa.'])
            ->assertNoRedirect();

        Livewire::test(EmailLogin::class, ['token' => $this->tokenFrom($mail)])
            ->call('consumeMagicLink')
            ->assertHasErrors(['data.email'])
            ->assertNoRedirect();

        $this->assertFalse(Auth::check());
        $this->assertNull(EmailOtp::query()->firstOrFail()->verified_at);
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_email_awaiting_legacy_review_can_log_in_and_becomes_verified(): void
    {
        $user = $this->createUser([
            'email_verified_at' => now()->subYear(),
            'email_verified_via' => 'legacy_review_required',
        ]);

        $this->loginWithCode($this->requestCode('staf@example.test'));

        $this->assertSame($user->id, Auth::id());
        $this->assertSame('email_link', $user->fresh()->email_verified_via);
    }

    public function test_unknown_deleted_or_shared_email_gets_the_same_response_without_a_code(): void
    {
        $this->createUser(['email' => 'terhapus@example.test', 'phone' => '6281200000001'])->delete();

        foreach (['tidak.dikenal@example.test', 'terhapus@example.test'] as $email) {
            Livewire::test(EmailLogin::class)
                ->fillForm(['email' => $email])
                ->call('send')
                ->assertHasNoFormErrors()
                ->assertSet('awaitingOtp', true)
                ->set('data.otp', '123456')
                ->call('verify')
                ->assertHasFormErrors(['otp' => 'Kode tidak valid atau sudah kedaluwarsa.'])
                ->assertNoRedirect();
        }

        Mail::assertNothingSent();
        $this->assertSame(0, EmailOtp::query()->count());
        $this->assertFalse(Auth::check());
    }

    public function test_invalid_email_is_rejected_before_sending(): void
    {
        Livewire::test(EmailLogin::class)
            ->fillForm(['email' => 'bukan-email'])
            ->call('send')
            ->assertHasFormErrors(['email'])
            ->assertSet('awaitingOtp', false);

        Mail::assertNothingSent();
    }

    public function test_failed_delivery_expires_the_code_and_shows_a_generic_error(): void
    {
        $this->createUser();

        Mail::shouldReceive('to')->once()->andThrow(new TransportException('Connection refused'));

        Livewire::test(EmailLogin::class)
            ->fillForm(['email' => 'staf@example.test'])
            ->call('send')
            ->assertHasFormErrors(['email' => 'Email belum bisa dikirim. Coba lagi sebentar lagi.'])
            ->assertSet('awaitingOtp', false);

        $this->assertTrue(EmailOtp::query()->firstOrFail()->expires_at->lte(now()));
    }

    public function test_email_login_is_unavailable_when_the_mailer_only_logs(): void
    {
        $this->createUser();
        config()->set('mail.default', 'log');

        $this->get(route('email-login'))
            ->assertOk()
            ->assertSee('Pengiriman email belum dikonfigurasi');

        Livewire::test(EmailLogin::class)
            ->fillForm(['email' => 'staf@example.test'])
            ->call('send')
            ->assertHasErrors(['data.email'])
            ->assertSet('awaitingOtp', false);

        Mail::assertNothingSent();
    }

    public function test_signed_in_user_is_redirected_away_from_email_login(): void
    {
        $user = $this->createUser([
            'email_verified_at' => now(),
            'email_verified_via' => 'admin',
        ]);

        $this->actingAs($user)
            ->get(route('email-login'))
            ->assertRedirect('/admin');
    }

    public function test_login_mail_contains_the_code_and_the_magic_link(): void
    {
        $url = 'http://helpdesk.test/email-login/link/'.str_repeat('a', 64);
        $html = (new EmailLoginMail(
            name: 'Staf Helpdesk',
            otp: '482913',
            loginUrl: $url,
            expiresInMinutes: 10,
        ))->render();

        $this->assertStringContainsString('482913', $html);
        $this->assertStringContainsString($url, $html);
        $this->assertStringContainsString('10 menit', $html);
    }

    private function createUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'name' => 'Staf Helpdesk',
            'email' => 'staf@example.test',
            'password' => null,
            'phone' => '6281234567890',
            'is_active' => true,
        ], $overrides));
    }

    private function requestCode(string $email): EmailLoginMail
    {
        Livewire::test(EmailLogin::class)
            ->fillForm(['email' => $email])
            ->call('send')
            ->assertHasNoFormErrors()
            ->assertSet('awaitingOtp', true);

        return $this->sentLoginMail($email);
    }

    /**
     * Komponen baru yang sudah berada di langkah kode untuk email ini, tanpa mengirim email baru: langkah kode
     * dibuka lewat email tak dikenal (yang tidak dikirimi apa pun), lalu email diganti ke target.
     */
    private function awaitingCodeFor(string $email): Testable
    {
        $this->fillerCount++;

        return Livewire::test(EmailLogin::class)
            ->fillForm(['email' => "pengisi.{$this->fillerCount}@example.test"])
            ->call('send')
            ->assertSet('awaitingOtp', true)
            ->set('data.email', $email);
    }

    private function loginWithCode(EmailLoginMail $mail): void
    {
        $this->awaitingCodeFor((string) $mail->to[0]['address'])
            ->set('data.otp', $mail->otp)
            ->call('verify')
            ->assertHasNoFormErrors()
            ->assertRedirect('/admin');
    }

    private function sentLoginMail(string $email): EmailLoginMail
    {
        $sent = Mail::sent(EmailLoginMail::class, fn (EmailLoginMail $mail): bool => $mail->hasTo($email));

        $this->assertNotEmpty($sent, "Tidak ada email login ke {$email}.");

        return $sent->last();
    }

    private function tokenFrom(EmailLoginMail $mail): string
    {
        return Str::afterLast($mail->loginUrl, '/');
    }

    private function createTestSchema(): void
    {
        foreach (['email_otps', 'activity_log', 'users'] as $table) {
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

        Schema::create('email_otps', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('email');
            $table->string('purpose', 32);
            $table->string('otp_hash');
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->timestamps();
        });
    }
}
