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
    }

    public function test_it_sends_whatsapp_message_to_configured_gateway(): void
    {
        config()->set('services.whatsapp_gateway.endpoint', 'https://api.fonnte.com/send');
        config()->set('services.whatsapp_gateway.token', 'secret-token');
        config()->set('services.whatsapp_gateway.country_code', '62');

        $history = [];
        $client = $this->clientWithResponses([
            new Response(200, [], '{"status":true,"detail":"success! message in queue"}'),
        ], $history);

        $gateway = new WhatsAppGateway($client);

        $this->assertTrue($gateway->send('+62 812-3456-7890', 'Halo'));
        $this->assertCount(1, $history);

        $request = $history[0]['request'];
        $body = (string) $request->getBody();

        $this->assertSame('https://api.fonnte.com/send', (string) $request->getUri());
        $this->assertSame('secret-token', $request->getHeaderLine('Authorization'));
        $this->assertStringContainsString('name="target"', $body);
        $this->assertStringContainsString('6281234567890', $body);
        $this->assertStringContainsString('name="message"', $body);
        $this->assertStringContainsString('Halo', $body);
        $this->assertStringContainsString('name="countryCode"', $body);
        $this->assertStringContainsString('62', $body);
    }

    public function test_it_normalizes_local_indonesian_phone_numbers(): void
    {
        config()->set('services.whatsapp_gateway.endpoint', 'https://api.fonnte.com/send');
        config()->set('services.whatsapp_gateway.token', 'secret-token');
        config()->set('services.whatsapp_gateway.country_code', '62');

        $history = [];
        $client = $this->clientWithResponses([
            new Response(200, [], '{"status":true}'),
            new Response(200, [], '{"status":true}'),
        ], $history);

        $gateway = new WhatsAppGateway($client);

        $this->assertTrue($gateway->send('0812-3456-7890', 'Halo'));
        $this->assertTrue($gateway->send('81234567890', 'Halo'));

        $this->assertStringContainsString('6281234567890', (string) $history[0]['request']->getBody());
        $this->assertStringContainsString('6281234567890', (string) $history[1]['request']->getBody());
    }

    public function test_it_treats_gateway_error_response_as_failed(): void
    {
        config()->set('services.whatsapp_gateway.endpoint', 'https://api.fonnte.com/send');
        config()->set('services.whatsapp_gateway.token', 'secret-token');

        $history = [];
        $client = $this->clientWithResponses([
            new Response(200, [], '{"Status":false,"reason":"token invalid"}'),
        ], $history);

        $gateway = new WhatsAppGateway($client);

        $this->assertFalse($gateway->send('081234567890', 'Halo'));
        $this->assertCount(1, $history);
    }

    public function test_it_does_not_send_invalid_phone_number(): void
    {
        config()->set('services.whatsapp_gateway.endpoint', 'https://api.fonnte.com/send');
        config()->set('services.whatsapp_gateway.token', 'secret-token');

        $history = [];
        $client = $this->clientWithResponses([], $history);

        $gateway = new WhatsAppGateway($client);

        $this->assertFalse($gateway->send('12345', 'Halo'));
        $this->assertCount(0, $history);
    }

    public function test_it_does_not_send_without_token(): void
    {
        config()->set('services.whatsapp_gateway.token', null);

        $history = [];
        $client = $this->clientWithResponses([], $history);

        $gateway = new WhatsAppGateway($client);

        $this->assertFalse($gateway->send('081234567890', 'Halo'));
        $this->assertCount(0, $history);
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
