<?php

namespace Tests\Feature;

use App\Mcp\Prompts\CreateTicketPrompt;
use App\Mcp\Servers\HelpdeskServer;
use App\Mcp\Tools\HelpdeskIntakeTool;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Attributes\Instructions;
use Tests\Concerns\CreatesHelpdeskIntegrationSchema;
use Tests\TestCase;

class HelpdeskMcpTest extends TestCase
{
    use CreatesHelpdeskIntegrationSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTestSchema();
        Cache::flush();
        Notification::fake();
        $this->withToken((string) config('services.helpdesk_mcp.token'));
    }

    public function test_mcp_http_rejects_missing_token(): void
    {
        $this->flushHeaders();

        $this->postJson('/mcp/helpdesk', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-03-26',
                'capabilities' => new \stdClass,
                'clientInfo' => ['name' => 'test', 'version' => '1'],
            ],
        ])
            ->assertUnauthorized()
            ->assertJsonPath('jsonrpc', '2.0')
            ->assertJsonPath('error.code', -32001)
            ->assertJsonPath('error.message', 'Token MCP Helpdesk tidak valid.');
    }

    public function test_registered_tool_is_only_helpdesk_intake(): void
    {
        $response = HelpdeskServer::tool(HelpdeskIntakeTool::class, [
            'phone' => '6281234567890',
            'message' => 'Odoo gabisa invoice',
            'channel' => 'whatsapp',
            'external_user_id' => 'wa-test-user',
        ]);

        $response->assertOk();
        $response->assertSee('Ketik *lapor*');
        $response->assertDontSee('HD-');
        $this->assertNotNull(Mcp::getLocalServer('helpdesk'));
    }

    public function test_http_accepts_each_configured_rotation_token(): void
    {
        config(['services.helpdesk_mcp.tokens' => ['old-client-secret', 'new-client-secret']]);

        foreach (['old-client-secret', 'new-client-secret'] as $index => $token) {
            $this->withToken($token)
                ->postJson('/mcp/helpdesk', [
                    'jsonrpc' => '2.0',
                    'id' => $index + 1,
                    'method' => 'initialize',
                    'params' => [
                        'protocolVersion' => '2025-03-26',
                        'capabilities' => new \stdClass,
                        'clientInfo' => ['name' => 'rotation-test', 'version' => '1'],
                    ],
                ])
                ->assertOk()
                ->assertJsonPath('result.serverInfo.name', 'helpdesk');
        }
    }

    public function test_only_mcp_helpdesk_transport_is_registered(): void
    {
        $postRoute = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($route): bool => $route->uri() === 'mcp/helpdesk'
                && in_array('POST', $route->methods(), true));
        $this->assertNotNull($postRoute);
        $middleware = $postRoute->middleware();
        $preAuthThrottle = array_search('throttle:mcp-pre-auth', $middleware, true);
        $tokenAuthentication = array_search('helpdesk.mcp', $middleware, true);
        $this->assertIsInt($preAuthThrottle);
        $this->assertIsInt($tokenAuthentication);
        $this->assertLessThan($tokenAuthentication, $preAuthThrottle);

        $legacyRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with(
                $route->uri(),
                'api/integrations/whatsapp/helpdesk',
            ));

        $this->assertCount(0, $legacyRoutes);

        $this->putJson('/mcp/helpdesk')
            ->assertMethodNotAllowed()
            ->assertHeader('Allow', 'POST')
            ->assertJsonPath('jsonrpc', '2.0')
            ->assertJsonPath('error.code', -32600)
            ->assertJsonPath('error.data.allowed_methods', 'POST')
            ->assertJsonMissingPath('exception')
            ->assertJsonMissingPath('trace');
    }

    public function test_mcp_contract_is_vendor_neutral_and_self_contained(): void
    {
        $instructions = (new \ReflectionClass(HelpdeskServer::class))
            ->getAttributes(Instructions::class)[0]
            ->newInstance()
            ->value;

        $this->assertStringContainsString('any MCP client', $instructions);
        $this->assertStringContainsString('intake_id', $instructions);
        $this->assertStringContainsString('WhatsApp', $instructions);
        $this->assertStringContainsString('OTP', $instructions);
        $this->assertStringContainsString('slack', $instructions);
        $this->assertStringNotContainsString('session_key', $instructions);

        $prompt = file_get_contents((new \ReflectionClass(CreateTicketPrompt::class))->getFileName());
        $this->assertStringContainsString('intake_id', $prompt);
        $this->assertStringContainsString('gateway must', $prompt);
        $this->assertStringContainsString('WhatsApp', $prompt);
        $this->assertStringContainsString('OTP', $prompt);
        $this->assertStringNotContainsString('session_key', $prompt);
    }
}
