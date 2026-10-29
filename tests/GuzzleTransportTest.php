<?php

declare(strict_types=1);

namespace SouthApi\Tests;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as HttpResponse;
use PHPUnit\Framework\TestCase;
use SouthApi\Client;
use SouthApi\Configuration;
use SouthApi\Exceptions\{ProtocolException, TransportException};
use SouthApi\Http\{GuzzleTransport, Request};
use SouthApi\Security\Credentials;

final class GuzzleTransportTest extends TestCase
{
    public function testSafeOptionsAndExactBytes(): void
    {
        $mock = new MockHandler([new HttpResponse(200, [], '{"outcome":0,"content":{}}')]);
        $stack = HandlerStack::create($mock);
        $history = [];
        $stack->push(Middleware::history($history));
        $configuration = new Configuration();
        $transport = new GuzzleTransport($configuration, new HttpClient(['handler' => $stack]));
        (new Client(new Credentials('00000000-0000-4000-8000-000000000000', 'test-payment-key', clock: static fn (): int => 1_800_000_000), $configuration, $transport))->payments->options();
        self::assertIsArray($history);
        self::assertCount(1, $history);
        self::assertSame('{}', (string) $history[0]['request']->getBody());
        self::assertSame('07ea66d911ac2849e4f36d0f767671121c26f9d8bc8287866345e1213645c6c3', $history[0]['request']->getHeaderLine('X-Signature'));
        self::assertSame('1800000000', $history[0]['request']->getHeaderLine('X-Timestamp'));
        self::assertFalse($history[0]['request']->hasHeader('sign'));
        self::assertTrue($history[0]['options']['verify']);
        self::assertFalse($history[0]['options']['allow_redirects']);
        self::assertFalse($history[0]['options']['http_errors']);
        self::assertFalse($history[0]['options']['debug']);
        self::assertSame(5.0, $history[0]['options']['connect_timeout']);
        self::assertSame(30.0, $history[0]['options']['timeout']);
    }

    public function testRedirectIsNotFollowed(): void
    {
        $mock = new MockHandler([new HttpResponse(302, ['Location' => 'https://other.example']), new HttpResponse(200)]);
        $client = new Client(transport: new GuzzleTransport(new Configuration(), new HttpClient(['handler' => HandlerStack::create($mock)])));
        try {
            $client->rates->all();
            self::fail('Redirect accepted.');
        } catch (ProtocolException) {
            self::assertSame(1, $mock->count());
        }
    }

    public function testBoundedReading(): void
    {
        $mock = new MockHandler([new HttpResponse(200, [], str_repeat('x', 65))]);
        $transport = new GuzzleTransport(new Configuration(maxResponseBytes: 64), new HttpClient(['handler' => $mock]));
        $this->expectException(ProtocolException::class);
        $transport->send(new Request('GET', 'https://api.southapi.com/v2/rates', [], ''));
    }

    public function testForeignDestinationRejectedBeforeHttp(): void
    {
        $mock = new MockHandler([new HttpResponse(200)]);
        $transport = new GuzzleTransport(new Configuration(), new HttpClient(['handler' => $mock]));
        try {
            $transport->send(new Request('GET', 'https://api.southapi.com.attacker.example/v2/rates', [], ''));
            self::fail('Foreign origin accepted.');
        } catch (TransportException) {
            self::assertSame(1, $mock->count());
        }
    }
    public function testDownloadIsBoundedBeforeBufferGrowth(): void
    {
        $mock = new MockHandler([new HttpResponse(200, [], str_repeat('x', 65))]);
        $transport = new GuzzleTransport(new Configuration(maxResponseBytes: 64), new HttpClient(['handler' => HandlerStack::create($mock)]));
        $this->expectException(ProtocolException::class);
        $transport->send(new Request('GET', 'https://api.southapi.com/v2/rates', [], ''));
    }

    public function testFactoryBlocksSecondAttemptBeforeCreatingCurlHandle(): void
    {
        $factory = new \SouthApi\Http\SingleAttemptCurlFactory();
        $request = new \GuzzleHttp\Psr7\Request('POST', 'https://api.southapi.com/v1/balances', [], '{}');
        $handle = $factory->create($request, []);
        $factory->release($handle);
        $this->expectException(TransportException::class);
        $factory->create($request, []);
    }

}
