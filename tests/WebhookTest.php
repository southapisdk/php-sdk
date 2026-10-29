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
        yield 'payment' => ['payment', '75f8c0bbefe01b66e702efc5275c964b4ce0fc2b2c16f3f127227ced64418772'];
        yield 'deposit' => ['deposit', 'db67e52bcd1a12fa155b25a82ea6ccd58b085fd106eecf1ecb9aeaa2fb376e51'];
        yield 'withdrawal' => ['withdrawal', '6850e90b2c799da52eb255123e9e8c284eb66fbf86cc8040943c53a20a2a7a2d'];
        yield 'blocked' => ['blocked_address_payment', 'ca7ed5456a5be70aa80301c462341b7837e17006e71adb012e4e50e97b4d64e2'];
    }

    private function verifier(int $now = 1_800_000_000): WebhookVerifier
    {
        return new WebhookVerifier(new Credentials(paymentKey: 'test-payment-key', payoutKey: 'test-payout-key'), static fn (): int => $now);
    }

    private static function body(string $kind): string
    {
        return '{"event_kind":"' . $kind . '","payment_reference":"00000000-0000-4000-8000-000000000001","display_total":"1.00000001"}';
    }

    #[DataProvider('vectors')]
    public function testKnownVectorsAndDuplicates(string $kind, string $signature): void
    {
        $verifier = $this->verifier();
        $event = $verifier->verify(self::body($kind), '1800000000', $signature);
        self::assertSame($kind, $event->content()['event_kind']);
        self::assertSame('1.00000001', $event->content()['display_total']);
        self::assertSame($event->content(), $verifier->verify(self::body($kind), '1800000000', $signature)->content());
    }

    /** @return iterable<string, array{string, string, string, int}> */
    public static function invalid(): iterable
    {
        $body = self::body('payment');
        $valid = '75f8c0bbefe01b66e702efc5275c964b4ce0fc2b2c16f3f127227ced64418772';
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
            self::assertSame('payment', $this->verifier($now)->verify(self::body('payment'), '1800000000', '75f8c0bbefe01b66e702efc5275c964b4ce0fc2b2c16f3f127227ced64418772')->content()['event_kind']);
        }
    }

    public function testEventKindCannotChooseWrongKey(): void
    {
        $body = self::body('blocked_address_payment');
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
