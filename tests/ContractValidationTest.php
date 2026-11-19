<?php

declare(strict_types=1);

namespace SouthApi\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SouthApi\Client;
use SouthApi\Exceptions\{ConfigurationException, ProtocolException};
use SouthApi\Http\Response;
use SouthApi\Security\Credentials;

final class ContractValidationTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidChargeFields(): iterable
    {
        yield 'callback scheme' => [['links' => ['callback_link' => 'ftp://example.com/callback']]];
        yield 'callback credentials' => [['links' => ['callback_link' => 'https://user@example.com/hook']]];
        yield 'loopback callback' => [['links' => ['callback_link' => 'http://127.0.0.1/hook']]];
        yield 'tolerance exceeds five percent' => [['pricing' => ['asset_code' => 'USD', 'display_total' => '1', 'payment_tolerance_rate' => '5.01']]];
        yield 'expiry below minimum' => [['checkout' => ['expires_in' => 299]]];
        yield 'amount array' => [['pricing' => ['display_total' => ['1'], 'asset_code' => 'USD']]];
        yield 'amount empty array' => [['pricing' => ['display_total' => [], 'asset_code' => 'USD']]];
        yield 'flag array' => [['checkout' => ['partial_allowed' => [true]]]];
        yield 'simulation array' => [['simulation' => ['simulated_success' => [false]]]];
        yield 'fee array' => [['pricing' => ['display_total' => '1', 'asset_code' => 'USD', 'fee_deducted' => [1]]]];
        yield 'discount array' => [['pricing' => ['display_total' => '1', 'asset_code' => 'USD', 'discount_rate' => [0]]]];
        yield 'order array' => [['merchant_order' => ['order-1']]];
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('invalidChargeFields')]
    public function testScalarFieldsRejectArraysBeforeSending(array $override): void
    {
        $transport = new RecordingTransport();
        try {
            $this->client($transport)->payments->create(array_replace([
                'merchant_order' => 'order-1', 'pricing' => ['display_total' => '1', 'asset_code' => 'USD'],
            ], $override));
            self::fail('Invalid scalar accepted.');
        } catch (ConfigurationException) {
            self::assertCount(0, $transport->requests);
        }
    }

    public function testAmountLengthDependsOnOperation(): void
    {
        $transport = new RecordingTransport(new Response(201, '{"outcome":0,"content":{}}'));
        $client = $this->client($transport);
        $boundary = '1.' . str_repeat('0', 38);
        $long = $boundary . '1';
        $client->payments->create(['merchant_order' => 'order-1', 'pricing' => ['display_total' => $boundary, 'asset_code' => 'USD']]);
        $client->transfers->toPersonal($long, 'USD');
        $cashout = ['amount' => ['display_total' => $long, 'asset_code' => 'USD'], 'chain' => ['destination' => 'synthetic-address'], 'fee_deducted' => true];
        foreach ([
            fn () => $client->payments->create(['merchant_order' => 'order-1', 'pricing' => ['display_total' => $long, 'asset_code' => 'USD']]),
            fn () => $client->withdrawals->create($cashout + ['merchant_order' => 'order-2']),
            fn () => $client->withdrawals->calculate($cashout),
            fn () => $client->payments->refund('ref', ['refund' => ['display_total' => $long], 'destination' => 'synthetic-address', 'fee_deducted' => false]),
        ] as $send) {
            try {
                $send();
                self::fail('Amount limit ignored.');
            } catch (ConfigurationException) {
                self::assertCount(2, $transport->requests);
            }
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function excludedQuoteFields(): iterable
    {
        foreach ([null, '', []] as $index => $value) {
            foreach (['merchant_order', 'links', 'simulation'] as $key) {
                yield $key . $index => [[$key => $value]];
            }
            yield 'memo' . $index => [['chain' => ['destination' => 'synthetic-address', 'memo' => $value]]];
            yield 'source_asset' . $index => [['amount' => ['display_total' => '1', 'asset_code' => 'USD', 'source_asset' => $value]]];
        }
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('excludedQuoteFields')]
    public function testQuoteRejectsPresenceOfExcludedFields(array $override): void
    {
        $transport = new RecordingTransport();
        try {
            $this->client($transport)->withdrawals->calculate(array_replace([
                'amount' => ['display_total' => '1', 'asset_code' => 'USD'],
                'chain' => ['destination' => 'synthetic-address'], 'fee_deducted' => false,
            ], $override));
            self::fail('Excluded field accepted.');
        } catch (ConfigurationException) {
            self::assertCount(0, $transport->requests);
        }
    }

    /** @return iterable<string, array{?string}> */
    public static function nonPdfTypes(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'JSON' => ['application/json'];
        yield 'HTML' => ['text/html'];
        yield 'prefix' => ['application/pdf-unknown'];
    }

    #[DataProvider('nonPdfTypes')]
    public function testPdfRejectsWrongContentType(?string $contentType): void
    {
        $transport = new RecordingTransport(new Response(200, '{"outcome":0,"content":null}', $contentType));
        $this->expectException(ProtocolException::class);
        $this->client($transport)->bills->pdf('ref');
    }

    public function testPdfAcceptsCaseInsensitiveMediaTypeWithParameters(): void
    {
        $bytes = "%PDF-1.7\n\x00\xff";
        $transport = new RecordingTransport(new Response(200, $bytes, ' Application/PDF; version=1.7'));
        self::assertSame($bytes, $this->client($transport)->bills->pdf('ref'));
    }

    private function client(RecordingTransport $transport): Client
    {
        return new Client(new Credentials('00000000-0000-4000-8000-000000000000', 'test-payment-key', 'test-payout-key'), transport: $transport);
    }
}
