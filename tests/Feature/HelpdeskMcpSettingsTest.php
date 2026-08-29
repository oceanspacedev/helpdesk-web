<?php

namespace Tests\Feature;

use App\Filament\Pages\HelpdeskMcpSettings;
use App\Mcp\Servers\HelpdeskServer;
use App\Mcp\Tools\HelpdeskIntakeTool;
use App\Models\HelpdeskMcpSetting;
use App\Models\User;
use App\Services\Integrations\HelpdeskMcpConfiguration;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Concerns\CreatesHelpdeskIntegrationSchema;
use Tests\TestCase;

class HelpdeskMcpSettingsTest extends TestCase
{
    use CreatesHelpdeskIntegrationSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTestSchema();
        app(HelpdeskMcpConfiguration::class)->forgetResolvedSettings();
    }

    public function test_environment_configuration_remains_the_fallback_before_the_ui_is_saved(): void
    {
        config()->set('services.helpdesk_mcp.tokens', ['fallback-token-one', 'fallback-token-two']);
        config()->set('services.helpdesk_mcp.intake_ttl_minutes', 45);
        config()->set('services.helpdesk_mcp.rate_limit_per_minute', 420);
        config()->set('services.helpdesk_mcp.identity_assertion_leeway_seconds', 180);
        config()->set('services.helpdesk_mcp.identity_pepper', 'fallback-pepper');
        config()->set('services.helpdesk_mcp.identity_assertion_secret', 'fallback-assertion-secret');

        $configuration = app(HelpdeskMcpConfiguration::class);
        $configuration->forgetResolvedSettings();

        $this->assertSame(['fallback-token-one', 'fallback-token-two'], $configuration->tokens());
        $this->assertSame(45, $configuration->intakeTtlMinutes());
        $this->assertSame(420, $configuration->rateLimitPerMinute());
        $this->assertSame(180, $configuration->identityAssertionLeewaySeconds());
        $this->assertSame('fallback-pepper', $configuration->identityPepper());
        $this->assertSame('fallback-assertion-secret', $configuration->identityAssertionSecret());
    }

    public function test_database_errors_fail_closed_instead_of_reactivating_legacy_tokens(): void
    {
        config()->set('services.helpdesk_mcp.tokens', ['revoked-legacy-token']);
        Schema::drop('helpdesk_mcp_settings');
        Schema::create('helpdesk_mcp_settings', function (Blueprint $table): void {
            $table->longText('tokens')->nullable();
        });

        $configuration = app(HelpdeskMcpConfiguration::class);
        $configuration->forgetResolvedSettings();

        $this->expectException(QueryException::class);
        $configuration->tokens();
    }

    public function test_database_configuration_overrides_environment_and_encrypts_sensitive_values(): void
    {
        $token = 'hdm_database_token_123456789012345678901234567890';
        $pepper = 'database-pepper-123456789012345678901234';
        $assertionSecret = 'database-assertion-secret-123456789012345';
        $configuration = app(HelpdeskMcpConfiguration::class);

        $configuration->save([
            'tokens' => [[
                'name' => 'WhatsApp Gateway',
                'token' => $token,
                'active' => true,
            ]],
            'intake_ttl_minutes' => 75,
            'rate_limit_per_minute' => 500,
            'identity_pepper' => $pepper,
            'identity_assertion_secret' => $assertionSecret,
            'identity_assertion_leeway_seconds' => 240,
        ]);

        $raw = DB::table('helpdesk_mcp_settings')->find(HelpdeskMcpSetting::SINGLETON_ID);
        $this->assertIsObject($raw);
        $this->assertStringNotContainsString($token, (string) $raw->tokens);
        $this->assertStringNotContainsString($pepper, (string) $raw->identity_pepper);
        $this->assertStringNotContainsString($assertionSecret, (string) $raw->identity_assertion_secret);

        $this->assertSame([$token], $configuration->tokens());
        $this->assertSame(75, $configuration->intakeTtlMinutes());
        $this->assertSame(500, $configuration->rateLimitPerMinute());
        $this->assertSame($pepper, $configuration->identityPepper());
        $this->assertSame($assertionSecret, $configuration->identityAssertionSecret());
        $this->assertSame(240, $configuration->identityAssertionLeewaySeconds());
    }

    public function test_blank_secret_inputs_preserve_the_existing_encrypted_values(): void
    {
        $configuration = app(HelpdeskMcpConfiguration::class);
        $base = [
            'tokens' => [],
            'intake_ttl_minutes' => 30,
            'rate_limit_per_minute' => 300,
            'identity_assertion_leeway_seconds' => 300,
        ];

        $configuration->save($base + [
            'identity_pepper' => 'pepper-123456789012345678901234567890',
            'identity_assertion_secret' => 'assertion-1234567890123456789012345678',
        ]);
        $configuration->save($base);

        $this->assertSame(
            'pepper-123456789012345678901234567890',
            $configuration->identityPepper(),
        );
        $this->assertSame(
            'assertion-1234567890123456789012345678',
            $configuration->identityAssertionSecret(),
        );
    }

    public function test_database_tokens_are_used_by_the_http_middleware(): void
    {
        $databaseToken = 'hdm_http_database_token_123456789012345678901234';
        $disabledToken = 'hdm_http_disabled_token_12345678901234567890123';
        config()->set('services.helpdesk_mcp.tokens', ['legacy-environment-token']);

        app(HelpdeskMcpConfiguration::class)->save([
            'tokens' => [
                [
                    'name' => 'Database Client',
                    'token' => $databaseToken,
                    'active' => true,
                ],
                [
                    'name' => 'Disabled Client',
                    'token' => $disabledToken,
                    'active' => false,
                ],
            ],
            'intake_ttl_minutes' => 30,
            'rate_limit_per_minute' => 300,
            'identity_assertion_leeway_seconds' => 300,
        ]);

        $payload = [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-03-26',
                'capabilities' => new \stdClass,
                'clientInfo' => ['name' => 'settings-test', 'version' => '1'],
            ],
        ];

        $this->withToken($databaseToken)
            ->postJson('/mcp/helpdesk', $payload)
            ->assertOk();

        $this->withToken('legacy-environment-token')
            ->postJson('/mcp/helpdesk', $payload)
            ->assertUnauthorized();

        $this->withToken($disabledToken)
            ->postJson('/mcp/helpdesk', $payload)
            ->assertUnauthorized();
    }

    public function test_long_running_stdio_tool_refreshes_settings_for_each_call(): void
    {
        $configuration = app(HelpdeskMcpConfiguration::class);
        $configuration->save([
            'tokens' => [],
            'intake_ttl_minutes' => 30,
            'rate_limit_per_minute' => 300,
            'identity_assertion_leeway_seconds' => 300,
        ]);
        $this->assertSame(30, $configuration->intakeTtlMinutes());

        DB::table('helpdesk_mcp_settings')
            ->where('id', HelpdeskMcpSetting::SINGLETON_ID)
            ->update(['intake_ttl_minutes' => 90]);

        HelpdeskServer::tool(HelpdeskIntakeTool::class, [
            'phone' => '6281234567890',
            'message' => 'lapor',
            'channel' => 'mcp',
        ])->assertStructuredContent(function ($json): void {
            $json
                ->where('expires_at', function (string $expiresAt): bool {
                    $expiry = Carbon::parse($expiresAt);

                    return $expiry->greaterThan(now()->addMinutes(89))
                        && $expiry->lessThan(now()->addMinutes(91));
                })
                ->etc();
        });
    }

    public function test_only_super_admin_can_open_and_save_the_settings_page(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();

        $regularUser = $this->verifiedUser('regular-settings@example.test');
        $this->actingAs($regularUser);
        $this->assertFalse(HelpdeskMcpSettings::canAccess());
        $this->get(HelpdeskMcpSettings::getUrl(panel: 'admin'))
            ->assertForbidden();

        $superAdmin = $this->verifiedUser('super-settings@example.test');
        $roleId = DB::table('roles')->insertGetId([
            'name' => 'Super Admin',
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('model_has_roles')->insert([
            'role_id' => $roleId,
            'model_type' => User::class,
            'model_id' => $superAdmin->id,
        ]);

        $this->actingAs($superAdmin);
        $this->assertTrue(HelpdeskMcpSettings::canAccess());

        $token = 'hdm_ui_token_12345678901234567890123456789012';
        Livewire::test(HelpdeskMcpSettings::class)
            ->fillForm([
                'tokens' => [[
                    'name' => 'UI Client',
                    'token' => $token,
                    'active' => true,
                ]],
                'intake_ttl_minutes' => 90,
                'rate_limit_per_minute' => 600,
                'identity_assertion_leeway_seconds' => 120,
            ])
            ->call('save')
            ->assertHasNoErrors();

        $settings = HelpdeskMcpSetting::query()->findOrFail(HelpdeskMcpSetting::SINGLETON_ID);
        $this->assertSame($token, $settings->tokens[0]['token']);
        $this->assertSame(90, $settings->intake_ttl_minutes);
        $this->assertSame(600, $settings->rate_limit_per_minute);
    }

    private function verifiedUser(string $email): User
    {
        return User::query()->create([
            'name' => 'Settings User',
            'email' => $email,
            'email_verified_at' => now(),
            'email_verified_via' => 'admin',
            'password' => 'secret',
            'is_active' => true,
        ]);
    }
}
