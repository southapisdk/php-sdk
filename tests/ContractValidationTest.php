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

    public function testFortyCharacterLimitOnlyAppliesToChargeAmount(): void
    {
        $transport = new RecordingTransport(new Response(201, '{"outcome":0,"content":{}}'));
        $client = $this->client($transport);
        $boundary = '1.' . str_repeat('0', 38);
        $long = $boundary . '1';
        $client->payments->create(['merchant_order' => 'order-1', 'pricing' => ['display_total' => $boundary, 'asset_code' => 'USD']]);
        $client->withdrawals->create(['merchant_order' => 'order-2', 'amount' => ['display_total' => $long, 'asset_code' => 'USD'], 'chain' => ['destination' => 'synthetic-address'], 'fee_deducted' => true]);
        $client->payments->refund('ref', ['refund' => ['display_total' => $long], 'destination' => 'synthetic-address', 'fee_deducted' => false]);
        $client->bills->create(['subject' => 'Test', 'category' => 'amount', 'pricing' => ['asset_code' => 'USD', 'pre_tax_total' => $long, 'tax_total' => $long], 'line_items' => [['line_label' => 'Test', 'units' => 1, 'unit_price' => $long]]]);
        $client->transfers->toPersonal($long, 'USD');
        self::assertCount(5, $transport->requests);
        self::assertStringContainsString($long, $transport->requests[1]->body);
        try {
            $client->payments->create(['merchant_order' => 'order-3', 'pricing' => ['display_total' => $long, 'asset_code' => 'USD']]);
            self::fail('Charge amount length ignored.');
        } catch (ConfigurationException) {
            self::assertCount(5, $transport->requests);
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
