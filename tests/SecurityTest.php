<?php

declare(strict_types=1);

namespace SouthApi\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SouthApi\Client;
use SouthApi\Configuration;
use SouthApi\Exceptions\{ApiException, ConfigurationException, ProtocolException, SdkException, TransportException};
use SouthApi\Http\{Request, Response, Session};
use SouthApi\Responses\Page;
use SouthApi\Security\{Credentials, KeyType, WebhookVerifier};
use LogicException;
use RuntimeException;

final class SecurityTest extends TestCase
{
    private function credentials(): Credentials
    {
        return new Credentials('00000000-0000-4000-8000-000000000000', 'test-payment-key', 'test-payout-key', static fn (): int => 1_800_000_000);
    }

    public function testSigningVectorsAreIndependent(): void
    {
        $credentials = $this->credentials();
        self::assertSame('07ea66d911ac2849e4f36d0f767671121c26f9d8bc8287866345e1213645c6c3', $credentials->headers(KeyType::Payment, '{}')['X-Signature']);
        self::assertSame('7eb09d054552c4af969a55f380e11a7b7de78f097e2b579e604353086c7e1700', $credentials->headers(KeyType::Payment, '')['X-Signature']);
    }

    public function testEachRequestUsesCurrentTimestamp(): void
    {
        $now = 1_800_000_000;
        $credentials = new Credentials(
            '00000000-0000-4000-8000-000000000000',
            'test-payment-key',
            clock: static function () use (&$now): int { return $now; },
        );
        $transport = new RecordingTransport();
        $client = new Client($credentials, transport: $transport);
        $client->balances->get();
        $now++;
        $client->balances->get();
        self::assertSame('1800000000', $transport->requests[0]->headers['X-Timestamp']);
        self::assertSame('1800000001', $transport->requests[1]->headers['X-Timestamp']);
        self::assertNotSame($transport->requests[0]->headers['X-Signature'], $transport->requests[1]->headers['X-Signature']);
        self::assertSame($transport->requests[0]->body, $transport->requests[1]->body);
    }

    public function testPublicRequestDoesNotUseSigningClock(): void
    {
        $credentials = new Credentials(clock: static function (): int {
            throw new RuntimeException('Public requests must not sign.');
        });
        $transport = new RecordingTransport();
        (new Client($credentials, transport: $transport))->rates->all();
        self::assertSame(['Content-Type' => 'application/json', 'Accept' => 'application/json'], $transport->requests[0]->headers);
    }

    public function testDefaultClockUsesCurrentUnixSeconds(): void
    {
        $before = time();
        $headers = (new Credentials('00000000-0000-4000-8000-000000000000', 'test-payment-key'))->headers(KeyType::Payment, '{}');
        self::assertGreaterThanOrEqual($before, (int) $headers['X-Timestamp']);
        self::assertLessThanOrEqual(time(), (int) $headers['X-Timestamp']);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $headers['X-Signature']);
        self::assertArrayNotHasKey('sign', $headers);
    }

    public function testUnicodeIsSignedExactlyOnce(): void
    {
        $transport = new RecordingTransport();
        $client = new Client($this->credentials(), transport: $transport);
        $client->payments->create(['display_total' => '1.00000001', 'asset_code' => 'USD', 'merchant_order' => 'test-order-1', 'metadata' => 'Привет/x']);
        $body = '{"display_total":"1.00000001","asset_code":"USD","merchant_order":"test-order-1","metadata":"\u041f\u0440\u0438\u0432\u0435\u0442\/x"}';
        self::assertSame($body, $transport->requests[0]->body);
        self::assertSame(hash_hmac('sha256', '1800000000.' . $body, 'test-payment-key'), $transport->requests[0]->headers['X-Signature']);
    }

    public function testMissingCredentialsNeverSend(): void
    {
        $transport = new RecordingTransport();
        try {
            (new Client(transport: $transport))->payments->options();
            self::fail('Missing credentials accepted.');
        } catch (ConfigurationException) {
            self::assertCount(0, $transport->requests);
        }
    }

    public function testPaymentOnlyClientCannotIssueWithdrawal(): void
    {
        $transport = new RecordingTransport();
        $client = new Client(new Credentials('00000000-0000-4000-8000-000000000000', 'test-payment-key'), transport: $transport);
        try {
            $client->withdrawals->options();
            self::fail('Missing payout key accepted.');
        } catch (ConfigurationException) {
            self::assertCount(0, $transport->requests);
        }
    }

    public function testPublicRatesWorkWithoutCredentialsAndEncodeAsset(): void
    {
        $transport = new RecordingTransport();
        (new Client(transport: $transport))->rates->get('USDT/https://other.example?q=x');
        self::assertSame('https://api.southapi.com/v1/rates/USDT%2Fhttps%3A%2F%2Fother.example%3Fq%3Dx', $transport->requests[0]->url);
        self::assertArrayNotHasKey('sign', $transport->requests[0]->headers);
        self::assertArrayNotHasKey('X-Signature', $transport->requests[0]->headers);
        self::assertArrayNotHasKey('X-Timestamp', $transport->requests[0]->headers);
    }

    public function testExplicitPagesKeepFiltersAndNeverFollowCursorAsUrl(): void
    {
        $transport = new RecordingTransport(new Response(200, '{"outcome":0,"content":{"entries":[],"pagination":{"item_count":0,"has_more":true,"next_cursor":"https://other.example/","previous_cursor":null,"page_size":15}}}'));
        $client = new Client($this->credentials(), transport: $transport);
        $first = $client->payments->search(['created_from' => '2026-01-01 00:00:00']);
        self::assertCount(1, $transport->requests);
        $client->payments->search(['created_from' => '2026-01-01 00:00:00'], $first->nextCursor);
        self::assertCount(2, $transport->requests);
        self::assertSame($transport->requests[0]->body, $transport->requests[1]->body);
        self::assertSame('https://api.southapi.com/v1/payments/search?cursor=https%3A%2F%2Fother.example%2F', $transport->requests[1]->url);
    }

    /** @return iterable<string, array{Response, class-string<SdkException>}> */
    public static function invalidResponses(): iterable
    {
        yield 'html' => [new Response(500, '<html>PRIVATE_MARKER</html>'), ProtocolException::class];
        yield 'bad json' => [new Response(200, '{'), ProtocolException::class];
        yield 'missing envelope' => [new Response(200, '{}'), ProtocolException::class];
        yield 'wrong outcome' => [new Response(200, '{"outcome":1,"content":{}}'), ProtocolException::class];
        yield 'null content' => [new Response(200, '{"outcome":0,"content":null}'), ProtocolException::class];
        yield 'redirect' => [new Response(302, 'PRIVATE_MARKER'), ProtocolException::class];
        yield 'unknown code' => [new Response(422, '{"error":{"code":"PRIVATE_MARKER","message":"PRIVATE_MARKER"}}'), ApiException::class];
        yield 'validation' => [new Response(422, '{"error":{"code":"validation_failed","message":"PRIVATE_MARKER","details":{"fields":{"address":"PRIVATE_MARKER"}}}}'), ApiException::class];
    }

    /** @param class-string<SdkException> $expected */
    #[DataProvider('invalidResponses')]
    public function testErrorsAreSafe(Response $response, string $expected): void
    {
        try {
            (new Client($this->credentials(), transport: new RecordingTransport($response)))->balances->get();
            self::fail('Invalid response accepted.');
        } catch (SdkException $exception) {
            self::assertInstanceOf($expected, $exception);
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString('PRIVATE_MARKER', (string) $exception);
            if ($exception instanceof ApiException) {
                self::assertContains($exception->errorCode, ['validation_failed', 'api_error']);
                self::assertSame(422, $exception->status);
            }
        }
    }

    public function testTransportExceptionDoesNotLeakOrRetryTransfer(): void
    {
        $transport = new RecordingTransport(new RuntimeException('PRIVATE_MARKER test-payout-key'));
        try {
            (new Client($this->credentials(), transport: $transport))->transfers->toBusiness('1.00', 'USDT');
            self::fail('Transport failure accepted.');
        } catch (TransportException $exception) {
            self::assertCount(1, $transport->requests);
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString('PRIVATE_MARKER', (string) $exception);
            self::assertStringNotContainsString('test-payout-key', (string) $exception);
        }
    }

    public function testDebugAndSerializationRedactSensitiveObjects(): void
    {
        $credentials = $this->credentials();
        $client = new Client($credentials, transport: new RecordingTransport());
        foreach ([$credentials, $client, $client->payments, new WebhookVerifier($credentials), new Request('POST', 'https://example.com', ['X-Signature' => 'PRIVATE_MARKER'], 'PRIVATE_MARKER')] as $object) {
            ob_start();
            var_dump($object);
            $debug = ob_get_clean();
            self::assertIsString($debug);
            self::assertStringNotContainsString('PRIVATE_MARKER', $debug);
            self::assertStringNotContainsString('test-payment-key', $debug);
            self::assertStringContainsString('[REDACTED]', $debug);
            try {
                serialize($object);
                self::fail('Sensitive serialization accepted.');
            } catch (LogicException $exception) {
                self::assertStringNotContainsString('test-payment-key', $exception->getMessage());
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function badOrigins(): iterable
    {
        foreach (['http://api.example', 'https://' . 'user:pass@api.example', 'https://user@api.example', 'https://api.example/path', 'https://api.example?q=x', 'https://api.example#fragment', "https://api.example\n", 'https://api.example\\@other.example'] as $url) {
            yield $url => [$url];
        }
    }

    #[DataProvider('badOrigins')]
    public function testInvalidOriginsRejected(string $origin): void
    {
        $this->expectException(ConfigurationException::class);
        new Configuration($origin);
    }

    public function testOversizeAndBadPaginationRejected(): void
    {
        try {
            (new Client($this->credentials(), new Configuration(maxResponseBytes: 5), new RecordingTransport()))->balances->get();
            self::fail('Oversized response accepted.');
        } catch (ProtocolException $exception) {
            self::assertNull($exception->getPrevious());
        }
        $this->expectException(ProtocolException::class);
        new Page(['entries' => [], 'pagination' => ['item_count' => 'wrong']]);
    }

    public function testRuntimeRequiredParametersAndAmountValidation(): void
    {
        $transport = new RecordingTransport();
        $session = new Session($this->credentials(), new Configuration(), $transport);
        foreach ([[], ['display_total' => 0.1, 'asset_code' => 'USD', 'merchant_order' => 'test-order-1']] as $parameters) {
            try {
                $session->json('POST', '/v1/payments', KeyType::Payment, $parameters);
                self::fail('Invalid parameters accepted.');
            } catch (ConfigurationException) {
                self::assertCount(0, $transport->requests);
            }
        }
    }
}
