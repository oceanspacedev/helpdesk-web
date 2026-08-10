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
    public function test_it_sends_whatsapp_message_via_wag(): void
    {
        config()->set('services.whatsapp_gateway.url', 'https://waghub.test');
        config()->set('services.whatsapp_gateway.token', 'test-secret-token');

        $history = [];
        $client = $this->clientWithResponses([
            new Response(200, [], '{"status":"success"}'),
        ], $history);

        $gateway = new WhatsAppGateway($client);

        $this->assertTrue($gateway->send('+62 812-3456-7890', 'Halo'));
        $this->assertCount(1, $history);

        $request = $history[0]['request'];

        $this->assertSame('https://waghub.test/api/v1/messages', (string) $request->getUri());
        $this->assertSame('Bearer test-secret-token', $request->getHeaderLine('Authorization'));

        $payload = json_decode((string) $request->getBody(), true);
        $this->assertSame('6281234567890', $payload['recipient']['value']);
        $this->assertSame('Halo', $payload['message']['text']);
        $this->assertSame('notification', $payload['purpose']);
        $this->assertSame('helpdesk', $payload['client_reference']);
    }

    public function test_it_handles_wag_failure_gracefully(): void
    {
        config()->set('services.whatsapp_gateway.url', 'https://waghub.test');
        config()->set('services.whatsapp_gateway.token', 'test-secret-token');

        $history = [];
        $client = $this->clientWithResponses([
            new Response(500, [], '{"error":"internal error"}'),
        ], $history);

        $gateway = new WhatsAppGateway($client);

        $this->assertFalse($gateway->send('081234567890', 'Halo'));
        $this->assertCount(1, $history);
    }

    public function test_it_normalizes_local_indonesian_phone_numbers(): void
    {
        $gateway = new WhatsAppGateway();

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

