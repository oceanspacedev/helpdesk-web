<?php

namespace Tests\Unit;

use App\Services\WhatsAppGateway;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

class WhatsAppGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.whatsapp_gateway.min_seconds_between_sends', 0);
        config()->set('services.whatsapp_gateway.min_digits', 10);
        config()->set('services.whatsapp_gateway.max_digits', 15);
        config()->set('services.whatsapp_gateway.fallback_enabled', false);
    }

    public function test_it_sends_whatsapp_message_via_waha_by_default(): void
    {
        config()->set('services.whatsapp_gateway.provider', 'waha');
        config()->set('services.whatsapp_gateway.waha.base_url', 'http://waha.local');
        config()->set('services.whatsapp_gateway.waha.api_key', 'waha-secret');
        config()->set('services.whatsapp_gateway.waha.session', 'default');

        $history = [];
        $client = $this->clientWithResponses([
            new Response(201, [], '{"id":"msg-1"}'),
        ], $history);

        $gateway = new WhatsAppGateway($client);

        $this->assertTrue($gateway->send('+62 812-3456-7890', 'Halo'));
        $this->assertCount(1, $history);

        $request = $history[0]['request'];

        $this->assertSame('http://waha.local/api/sendText', (string) $request->getUri());
        $this->assertSame('waha-secret', $request->getHeaderLine('X-Api-Key'));

        $payload = json_decode((string) $request->getBody(), true);
        $this->assertSame('default', $payload['session']);
        $this->assertSame('6281234567890@c.us', $payload['chatId']);
        $this->assertSame('Halo', $payload['text']);
    }

    public function test_it_falls_back_to_fonnte_when_waha_fails(): void
    {
        config()->set('services.whatsapp_gateway.provider', 'waha');
        config()->set('services.whatsapp_gateway.fallback_provider', 'fonnte');
        config()->set('services.whatsapp_gateway.fallback_enabled', true);
        config()->set('services.whatsapp_gateway.waha.base_url', 'http://waha.local');
        config()->set('services.whatsapp_gateway.fonnte.endpoint', 'https://api.fonnte.com/send');
        config()->set('services.whatsapp_gateway.fonnte.token', 'fonnte-secret');

        $history = [];
        $client = $this->clientWithResponses([
            new Response(500, [], '{"error":"session not ready"}'),
            new Response(200, [], '{"status":true}'),
        ], $history);

        $gateway = new WhatsAppGateway($client);

        $this->assertTrue($gateway->send('081234567890', 'Halo'));
        $this->assertCount(2, $history);
        $this->assertSame('http://waha.local/api/sendText', (string) $history[0]['request']->getUri());
        $this->assertSame('https://api.fonnte.com/send', (string) $history[1]['request']->getUri());
    }

    public function test_it_normalizes_local_indonesian_phone_numbers(): void
    {
        config()->set('services.whatsapp_gateway.provider', 'fonnte');
        config()->set('services.whatsapp_gateway.fonnte.endpoint', 'https://api.fonnte.com/send');
        config()->set('services.whatsapp_gateway.fonnte.token', 'secret-token');
        config()->set('services.whatsapp_gateway.country_code', '62');

        $history = [];
        $client = $this->clientWithResponses([
            new Response(200, [], '{"status":true}'),
            new Response(200, [], '{"status":true}'),
        ], $history);

        $gateway = new WhatsAppGateway($client);

        $this->assertSame('6281234567890', $gateway->normalizeTarget('0812-3456-7890'));
        $this->assertSame('6281234567890', $gateway->normalizeTarget('81234567890'));
    }

    private function clientWithResponses(array $responses, array &$history): Client
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        return new Client([
            'handler' => $stack,
        ]);
    }
}
