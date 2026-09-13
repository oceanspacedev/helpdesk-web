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

    public function test_each_request_sets_connect_and_total_timeouts(): void
    {
        config()->set('services.whatsapp_gateway.url', 'https://waghub.test');
        config()->set('services.whatsapp_gateway.token', 'test-secret-token');
        config()->set('services.whatsapp_gateway.timeout', 8);
        config()->set('services.whatsapp_gateway.connect_timeout', 5);

        $captured = [];
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], '{"status":"success"}'),
        ]));
        $stack->push(function (callable $handler) use (&$captured) {
            return function ($request, array $options) use ($handler, &$captured) {
                $captured = $options;

                return $handler($request, $options);
            };
        });
        $stack->push(Middleware::history($history));

        $gateway = new WhatsAppGateway(new Client(['handler' => $stack]));

        $this->assertTrue($gateway->send('081234567890', 'Halo'));
        $this->assertSame(8.0, $captured['timeout']);
        $this->assertSame(5.0, $captured['connect_timeout']);
        $this->assertFalse($captured['http_errors']);
        $this->assertCount(1, $history);
    }

    public function test_it_returns_false_when_the_gateway_times_out(): void
    {
        config()->set('services.whatsapp_gateway.url', 'https://waghub.test');
        config()->set('services.whatsapp_gateway.token', 'test-secret-token');

        $client = new Client([
            'handler' => HandlerStack::create(new MockHandler([
                new \GuzzleHttp\Exception\ConnectException(
                    'cURL error 28: Operation timed out',
                    new \GuzzleHttp\Psr7\Request('POST', 'https://waghub.test/api/v1/messages'),
                ),
            ])),
        ]);

        $gateway = new WhatsAppGateway($client);

        $this->assertFalse($gateway->send('081234567890', 'Halo'));
    }

    public function test_it_skips_send_when_php_execution_time_is_almost_exhausted(): void
    {
        config()->set('services.whatsapp_gateway.url', 'https://waghub.test');
        config()->set('services.whatsapp_gateway.token', 'test-secret-token');

        $history = [];
        $gateway = new class($this->clientWithResponses([
            new Response(200, [], '{"status":"success"}'),
        ], $history)) extends WhatsAppGateway
        {
            public function timeouts(?int $maxExecutionTime = null, ?float $requestStartedAt = null): ?array
            {
                return null;
            }
        };

        $this->assertFalse($gateway->send('081234567890', 'Halo'));
        $this->assertCount(0, $history);
    }

    public function test_container_does_not_inject_a_guzzle_client_without_timeouts(): void
    {
        $gateway = $this->app->make(WhatsAppGateway::class);
        $property = new \ReflectionProperty($gateway, 'client');
        $property->setAccessible(true);
        $client = $property->getValue($gateway);

        $this->assertNull($client);
        $timeouts = $gateway->timeouts(0, microtime(true));
        $this->assertSame(8.0, $timeouts['timeout']);
        $this->assertSame(5.0, $timeouts['connect_timeout']);
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

