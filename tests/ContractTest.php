<?php

declare(strict_types=1);

namespace SouthApi\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SouthApi\Client;
use SouthApi\Http\Response;
use SouthApi\Responses\Page;
use SouthApi\Responses\Result;
use SouthApi\Security\Credentials;
use SouthApi\Tests\Fixtures\Operations;

/** @phpstan-import-type Operation from Operations */
final class ContractTest extends TestCase
{
    /** @return iterable<string, array{Operation}> */
    public static function operations(): iterable
    {
        foreach (Operations::all() as $section => $operation) {
            yield $section => [$operation];
        }
    }

    /** @param Operation $operation */
    #[DataProvider('operations')]
    public function testEveryDocumentedOperation(array $operation): void
    {
        $transport = new RecordingTransport(new Response(200, $operation['response']));
        $client = new Client(new Credentials('00000000-0000-4000-8000-000000000000', 'test-payment-key', 'test-payout-key', static fn (): int => 1_800_000_000), transport: $transport);
        $result = ($operation['invoke'])($client);
        self::assertCount(1, $transport->requests);
        $request = $transport->requests[0];
        self::assertSame($operation['method'], $request->method);
        self::assertSame('https://api.southapi.com' . $operation['path'] . ($operation['query'] === '' ? '' : '?' . $operation['query']), $request->url);
        self::assertSame($operation['body'], $request->body);
        if ($operation['key'] === 'none') {
            self::assertArrayNotHasKey('merchant', $request->headers);
            self::assertArrayNotHasKey('X-Signature', $request->headers);
            self::assertArrayNotHasKey('X-Timestamp', $request->headers);
        } else {
            self::assertSame('00000000-0000-4000-8000-000000000000', $request->headers['merchant']);
            $key = $operation['key'] === 'payment' ? 'test-payment-key' : 'test-payout-key';
            self::assertSame('1800000000', $request->headers['X-Timestamp']);
            self::assertSame(hash_hmac('sha256', '1800000000.' . $operation['body'], $key), $request->headers['X-Signature']);
        }
        self::assertArrayNotHasKey('sign', $request->headers);
        if (is_string($result)) {
            self::assertSame($operation['response'], $result);
        } else {
            self::assertInstanceOf(Result::class, $result);
            $expected = json_decode($operation['response'], true, 64, JSON_THROW_ON_ERROR);
            self::assertIsArray($expected);
            self::assertSame($expected['content'], $result->content());
            if ($operation['page']) {
                self::assertInstanceOf(Page::class, $result);
                $content = $result->content();
                self::assertIsArray($content['pagination']);
                self::assertSame($content['pagination']['next_cursor'], $result->nextCursor);
                self::assertSame($content['pagination']['has_more'], $result->hasPages);
                self::assertSame($result->nextCursor !== null && $result->nextCursor !== '', $result->hasMore);
                self::assertSame($content['entries'], $result->entries());
            }
        }
    }

    public function testInventoryContainsExactlyDocumentedSections(): void
    {
        self::assertCount(32, Operations::all());
        self::assertArrayHasKey('rates-xml', Operations::all());
        self::assertSame('payment', Operations::all()['refund-blocked-wallet']['key']);
    }
}
