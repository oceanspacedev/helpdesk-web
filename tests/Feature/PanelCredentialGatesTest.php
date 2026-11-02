<?php

namespace Tests\Feature;

use App\Filament\Livewire\PersonalInfo;
use App\Filament\Livewire\UpdatePassword;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class PanelCredentialGatesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createTestSchema();
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
        foreach (['model_has_roles', 'roles', 'activity_log', 'users'] as $table) {
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
    }
}
