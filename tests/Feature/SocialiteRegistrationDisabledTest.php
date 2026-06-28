<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\SocialiteController;
use App\Models\SocialiteUser;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
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

    public function test_existing_helpdesk_user_can_still_link_socialite_login_when_registration_is_disabled(): void
    {
        config()->set('filament-socialite.registration', false);

        $existingUser = User::create([
            'name' => 'User Existing',
            'email' => 'existing@example.test',
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
            $table->string('password')->nullable();
            $table->string('remember_token')->nullable();
            $table->string('identity')->nullable();
            $table->string('phone', 20)->nullable()->unique();
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
    ) {
    }

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
