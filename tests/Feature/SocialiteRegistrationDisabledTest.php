<?php

namespace Tests\Feature;

use App\Filament\Livewire\PersonalInfo;
use App\Filament\Livewire\UpdatePassword;
use App\Http\Controllers\Auth\SocialiteController;
use App\Models\SocialiteUser;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SocialiteRegistrationDisabledTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createTestSchema();
    }

    public function test_socialite_registration_is_disabled_by_configuration(): void
    {
        $this->assertFalse(config('filament-socialite.registration'));
    }

    public function test_unverified_email_profile_and_password_reset_routes_are_disabled(): void
    {
        $panel = Filament::getPanel('admin');

        $this->assertFalse($panel->hasProfile());
        $this->assertFalse($panel->hasPasswordReset());
        $this->assertNull(app('router')->getRoutes()->getByName('filament.admin.auth.profile'));
        $this->assertNull(app('router')->getRoutes()->getByName('filament.admin.auth.password-reset.request'));
        $this->assertNull(app('router')->getRoutes()->getByName('filament.admin.auth.password-reset.reset'));
    }

    public function test_unknown_socialite_user_is_not_auto_registered_when_registration_is_disabled(): void
    {
        config()->set('filament-socialite.registration', false);
        Role::create(['name' => 'User', 'guard_name' => 'web']);

        $user = (new SocialiteController)->findOrCreateUser(
            new FakeSocialiteUser('google-001', 'Pengguna Baru', 'baru@example.test'),
            'google'
        );

        $this->assertNull($user);
        $this->assertSame(0, User::count());
        $this->assertSame(0, SocialiteUser::count());
    }

    public function test_configuration_cannot_enable_unknown_socialite_auto_registration(): void
    {
        config()->set('filament-socialite.registration', true);

        $user = (new SocialiteController)->findOrCreateUser(
            new FakeSocialiteUser('google-config-bypass', 'Pengguna Baru', 'config-bypass@example.test'),
            'google',
        );

        $this->assertNull($user);
        $this->assertSame(0, User::count());
        $this->assertSame(0, SocialiteUser::count());
    }

    public function test_existing_helpdesk_user_can_still_link_socialite_login_when_registration_is_disabled(): void
    {
        config()->set('filament-socialite.registration', false);

        $existingUser = User::create([
            'name' => 'User Existing',
            'email' => 'existing@example.test',
            'email_verified_at' => now(),
            'email_verified_via' => 'admin',
            'password' => null,
            'is_active' => true,
        ]);

        $user = (new SocialiteController)->findOrCreateUser(
            new FakeSocialiteUser('google-002', 'User Existing', 'existing@example.test'),
            'google'
        );

        $this->assertTrue($existingUser->is($user));
        $this->assertSame(1, User::count());
        $this->assertDatabaseHas('socialite_users', [
            'user_id' => $existingUser->id,
            'provider' => 'google',
            'provider_id' => 'google-002',
        ]);
    }

    public function test_unverified_helpdesk_email_cannot_be_linked_by_socialite(): void
    {
        config()->set('filament-socialite.registration', false);

        User::query()->create([
            'name' => 'Email Belum Terverifikasi',
            'email' => 'unverified@example.test',
            'email_verified_at' => null,
            'password' => null,
            'phone' => '6281200008888',
            'is_active' => true,
        ]);

        $user = (new SocialiteController)->findOrCreateUser(
            new FakeSocialiteUser('google-unverified', 'Claimed Name', 'unverified@example.test'),
            'google',
        );

        $this->assertNull($user);
        $this->assertSame(0, SocialiteUser::count());
    }

    public function test_existing_socialite_link_requires_an_active_user_with_trusted_email_provenance(): void
    {
        $trustedUser = User::query()->create([
            'name' => 'Linked Trusted User',
            'email' => 'linked-trusted@example.test',
            'email_verified_at' => now(),
            'email_verified_via' => 'admin',
            'password' => null,
            'is_active' => true,
        ]);
        $legacyUser = User::query()->create([
            'name' => 'Linked Legacy User',
            'email' => 'linked-legacy@example.test',
            'email_verified_at' => now(),
            'email_verified_via' => 'legacy_review_required',
            'password' => null,
            'is_active' => true,
        ]);
        $inactiveUser = User::query()->create([
            'name' => 'Linked Inactive User',
            'email' => 'linked-inactive@example.test',
            'email_verified_at' => now(),
            'email_verified_via' => 'admin',
            'password' => null,
            'is_active' => false,
        ]);
        $deletedUser = User::query()->create([
            'name' => 'Linked Deleted User',
            'email' => 'linked-deleted@example.test',
            'email_verified_at' => now(),
            'email_verified_via' => 'admin',
            'password' => null,
            'is_active' => true,
        ]);

        foreach ([
            [$trustedUser, 'google-linked-trusted'],
            [$legacyUser, 'google-linked-legacy'],
            [$inactiveUser, 'google-linked-inactive'],
            [$deletedUser, 'google-linked-deleted'],
        ] as [$user, $providerId]) {
            $user->socialiteUsers()->create([
                'provider' => 'google',
                'provider_id' => $providerId,
            ]);
        }
        $deletedUser->delete();

        $controller = new SocialiteController;
        $this->assertTrue($trustedUser->is($controller->findOrCreateUser(
            new FakeSocialiteUser('google-linked-trusted', 'Trusted', 'ignored@example.test'),
            'google',
        )));
        $this->assertNull($controller->findOrCreateUser(
            new FakeSocialiteUser('google-linked-legacy', 'Legacy', 'linked-legacy@example.test'),
            'google',
        ));
        $this->assertNull($controller->findOrCreateUser(
            new FakeSocialiteUser('google-linked-inactive', 'Inactive', 'linked-inactive@example.test'),
            'google',
        ));
        $this->assertNull($controller->findOrCreateUser(
            new FakeSocialiteUser('google-linked-deleted', 'Deleted', 'linked-deleted@example.test'),
            'google',
        ));
        $this->assertSame(4, SocialiteUser::count());
        $this->assertSame(4, User::withTrashed()->count());
    }

    public function test_self_service_profile_cannot_set_email_or_change_phone(): void
    {
        $user = User::query()->create([
            'name' => 'Nama Awal',
            'email' => null,
            'email_verified_at' => null,
            'password' => null,
            'phone' => '6281200009999',
            'is_active' => true,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        $plugin = Filament::getCurrentPanel()->getPlugin('filament-breezy');
        $this->assertSame(
            PersonalInfo::class,
            $plugin->getRegisteredMyProfileComponents()['personal_info'] ?? null,
        );

        $this->actingAs($user);
        Livewire::test(PersonalInfo::class)
            ->set('data.name', 'Nama Baru')
            ->set('data.email', 'claimed@example.test')
            ->set('data.phone', '6281200000000')
            ->call('submit')
            ->assertHasNoErrors();

        $user->refresh();
        $this->assertSame('Nama Baru', $user->name);
        $this->assertNull($user->email);
        $this->assertSame('6281200009999', $user->phone);
        $this->assertNull($user->email_verified_at);
    }

    public function test_password_update_is_available_only_to_a_trusted_email_account_with_an_existing_password(): void
    {
        $phoneOnlyUser = User::query()->create([
            'name' => 'Phone Only',
            'email' => null,
            'email_verified_at' => null,
            'password' => null,
            'phone' => '6281200010001',
            'is_active' => true,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        $this->actingAs($phoneOnlyUser);

        $plugin = Filament::getCurrentPanel()->getPlugin('filament-breezy');
        $this->assertArrayNotHasKey('update_password', $plugin->getRegisteredMyProfileComponents());
        Livewire::test(UpdatePassword::class)->assertForbidden();

        $trustedUser = User::query()->create([
            'name' => 'Trusted Password User',
            'email' => 'trusted-password@example.test',
            'email_verified_at' => now(),
            'email_verified_via' => 'admin',
            'password' => Hash::make('old-password'),
            'phone' => null,
            'is_active' => true,
        ]);
        $this->actingAs($trustedUser);

        $this->assertSame(
            UpdatePassword::class,
            $plugin->getRegisteredMyProfileComponents()['update_password'] ?? null,
        );
        Livewire::test(UpdatePassword::class)
            ->fillForm([
                'current_password' => 'old-password',
                'new_password' => 'new-password-123',
                'new_password_confirmation' => 'new-password-123',
            ])
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('new-password-123', (string) $trustedUser->fresh()->password));
    }

    private function createTestSchema(): void
    {
        foreach (['socialite_users', 'model_has_roles', 'roles', 'activity_log', 'users'] as $table) {
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

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name')->default('web');
            $table->timestamps();
        });

        Schema::create('model_has_roles', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
        });

        Schema::create('socialite_users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('provider');
            $table->string('provider_id');
            $table->timestamps();
            $table->unique(['provider', 'provider_id']);
        });
    }
}

class FakeSocialiteUser
{
    public function __construct(
        public string $id,
        private string $name,
        private string $email,
    ) {}

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getEmail(): string
    {
        return $this->email;
    }
}
