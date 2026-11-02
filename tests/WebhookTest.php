<?php

declare(strict_types=1);

namespace SouthApi\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SouthApi\Exceptions\WebhookException;
use SouthApi\Security\{Credentials, WebhookVerifier};

final class WebhookTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function vectors(): iterable
    {
        yield 'payment' => ['payment', 'a19ce150f8158894e21fdd857b4b46a67b80a1881dc132206c4e4149878e7711'];
        yield 'deposit' => ['deposit', '5e2b31d4d335ca7ab70590d34f817615af60aaafae6b352609de5ff8424a7baa'];
        yield 'withdrawal' => ['withdrawal', '14b7f65a0e6ded2d39a9fa106a7240b402cf4d6d19ce28769dc36037a77bc5e0'];
        yield 'blocked' => ['blocked_deposit', 'c4e4dc1848e229ce090ac7c0ddce1c91cab95327d846b74c45c9cfbe69a68b54'];
    }

    private function verifier(int $now = 1_800_000_000): WebhookVerifier
    {
        return new WebhookVerifier(new Credentials(paymentKey: 'test-payment-key', payoutKey: 'test-payout-key'), static fn (): int => $now);
    }

    private static function body(string $kind): string
    {
        return '{"event_kind":"' . $kind . '","data":{"payment_reference":"00000000-0000-4000-8000-000000000001","amounts":{"display_total":"1.00000001"},"conversion":null}}';
    }

    #[DataProvider('vectors')]
    public function testKnownVectorsAndDuplicates(string $kind, string $signature): void
    {
        $verifier = $this->verifier();
        $event = $verifier->verify(self::body($kind), '1800000000', $signature);
        self::assertSame($kind, $event->arrayContent()['event_kind']);
        self::assertSame(['display_total' => '1.00000001'], $event->arrayContent()['data']['amounts']);
        self::assertSame($event->content(), $verifier->verify(self::body($kind), '1800000000', $signature)->content());
    }

    /** @return iterable<string, array{string, string, string, int}> */
    public static function invalid(): iterable
    {
        $body = self::body('payment');
        $valid = 'a19ce150f8158894e21fdd857b4b46a67b80a1881dc132206c4e4149878e7711';
        yield 'request signature is not webhook signature' => [$body, '1800000000', hash_hmac('sha256', '1800000000.POST./api/v1/charges.' . $body, 'test-payment-key'), 1_800_000_000];
        yield 'past' => [$body, '1800000000', $valid, 1_800_000_301];
        yield 'future' => [$body, '1800000000', $valid, 1_799_999_699];
        yield 'changed bytes' => [$body . ' ', '1800000000', $valid, 1_800_000_000];
        $bodySigned = '{"type":"payment","amount":"1.00000001","sign":"8a1614b1fa066a604c0fe421e7ce3e86"}';
        yield 'body signature without headers' => [$bodySigned, '', '', 1_800_000_000];
        yield 'body signature as header' => [$bodySigned, '1800000000', '8a1614b1fa066a604c0fe421e7ce3e86', 1_800_000_000];
        yield 'old schema with valid HMAC' => [$bodySigned, '1800000000', hash_hmac('sha256', '1800000000.' . $bodySigned, 'test-payment-key'), 1_800_000_000];
        yield 'missing time' => [$body, '', $valid, 1_800_000_000];
        yield 'bad time' => [$body, '1800000000x', $valid, 1_800_000_000];
        yield 'overflow' => [$body, str_repeat('9', 40), $valid, 1_800_000_000];
        yield 'missing sign' => [$body, '1800000000', '', 1_800_000_000];
        yield 'uppercase' => [$body, '1800000000', strtoupper($valid), 1_800_000_000];
        yield 'wrong sign' => [$body, '1800000000', str_repeat('0', 64), 1_800_000_000];
        yield 'bad json' => ['{', '1800000000', $valid, 1_800_000_000];
        yield 'unknown' => [self::body('unknown'), '1800000000', $valid, 1_800_000_000];
        yield 'array' => ['[]', '1800000000', $valid, 1_800_000_000];
        yield 'oversize' => [str_repeat('x', 1_048_577), '1800000000', $valid, 1_800_000_000];
    }

    #[DataProvider('invalid')]
    public function testRejectsUntrustedWebhook(string $body, string $timestamp, string $signature, int $now): void
    {
        $this->expectException(WebhookException::class);
        $this->verifier($now)->verify($body, $timestamp, $signature);
    }

    public function testBothClockBoundaries(): void
    {
        foreach ([1_799_999_700, 1_800_000_300] as $now) {
            self::assertSame('payment', $this->verifier($now)->verify(self::body('payment'), '1800000000', 'a19ce150f8158894e21fdd857b4b46a67b80a1881dc132206c4e4149878e7711')->arrayContent()['event_kind']);
        }
    }

    public function testEventKindCannotChooseWrongKey(): void
    {
        $body = self::body('blocked_deposit');
        $signature = hash_hmac('sha256', '1800000000.' . $body, 'test-payment-key');
        $this->expectException(WebhookException::class);
        $this->verifier()->verify($body, '1800000000', $signature);
    }

    public function testMissingKeyFailsClosed(): void
    {
        $this->expectException(WebhookException::class);
        (new WebhookVerifier(new Credentials(), static fn (): int => 1_800_000_000))->verify(self::body('payment'), '1800000000', str_repeat('0', 64));
    }
}
