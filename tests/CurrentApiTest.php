<?php

declare(strict_types=1);

namespace SouthApi\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SouthApi\Client;
use SouthApi\Exceptions\ConfigurationException;
use SouthApi\Http\{Parameters, Response};
use SouthApi\Responses\Page;
use SouthApi\Security\Credentials;

final class CurrentApiTest extends TestCase
{
    public function testLastPageIgnoresRawHasMoreFlag(): void
    {
        $content = ['entries' => [], 'pagination' => [
            'item_count' => 0, 'has_more' => true, 'next_cursor' => null,
            'previous_cursor' => 'previous', 'page_size' => 15,
        ]];
        $page = new Page($content);
        self::assertFalse($page->hasMore);
        self::assertTrue($page->hasPages);
        self::assertSame($content, $page->content());
        $content['pagination']['has_more'] = false;
        $content['pagination']['next_cursor'] = 'next';
        self::assertTrue((new Page($content))->hasMore);
    }

    public function testBalancesPreserveServerArrayAndExposeWallets(): void
    {
        $transport = new RecordingTransport(new Response(200, '{"outcome":0,"content":[{"balances":{"business":[{"available_balance":"123456789.00000001","asset_code":"USDT"}],"personal":[]}}]}'));
        $client = new Client(new Credentials('00000000-0000-4000-8000-000000000000', 'test-payment-key'), transport: $transport);
        $balances = $client->balances->get();
        self::assertSame('123456789.00000001', $balances->business()[0]['available_balance']);
        self::assertSame([], $balances->personal());
        self::assertArrayHasKey(0, $balances->content());
        $transport->response = new Response(200, '{"outcome":0,"content":[[]]}');
        self::assertSame([], $client->balances->get()->business());
    }

    public function testSimulationFlagsArePreservedWithCallerOrder(): void
    {
        $transport = new RecordingTransport();
        $client = new Client(new Credentials('00000000-0000-4000-8000-000000000000', 'test-payment-key', 'test-payout-key'), transport: $transport);
        $client->payments->create(['display_total' => '1.00', 'asset_code' => 'USD', 'merchant_order' => 'merchant-a-order-1', 'simulated_success' => true, 'simulated_failure' => null, 'refresh_existing' => 1]);
        $client->withdrawals->create(['display_total' => '1.00', 'asset_code' => 'USDT', 'merchant_order' => 'merchant-b-order-1', 'destination' => 'synthetic-address', 'fee_deducted' => '0', 'simulated_success' => '1', 'simulated_failure' => false]);
        self::assertSame('{"display_total":"1.00","asset_code":"USD","merchant_order":"merchant-a-order-1","simulated_success":true,"simulated_failure":null,"refresh_existing":1}', $transport->requests[0]->body);
        self::assertSame('{"display_total":"1.00","asset_code":"USDT","merchant_order":"merchant-b-order-1","destination":"synthetic-address","fee_deducted":"0","simulated_success":"1","simulated_failure":false}', $transport->requests[1]->body);
    }

    /** @return iterable<string, array{string}> */
    public static function badAddresses(): iterable
    {
        foreach (['short', 'synthetic.address', 'synthetic:address', 'synthetic address', str_repeat('a', 129)] as $destination) {
            yield $destination => [$destination];
        }
    }

    #[DataProvider('badAddresses')]
    public function testInvalidDestinationFailsBeforeTransport(string $destination): void
    {
        $transport = new RecordingTransport();
        $client = new Client(new Credentials('00000000-0000-4000-8000-000000000000', payoutKey: 'test-payout-key'), transport: $transport);
        try {
            $client->withdrawals->calculate(['display_total' => '1.00', 'asset_code' => 'USDT', 'destination' => $destination, 'fee_deducted' => false]);
            self::fail('Invalid destination accepted.');
        } catch (ConfigurationException $exception) {
            self::assertCount(0, $transport->requests);
            self::assertStringNotContainsString($destination, $exception->getMessage());
        }
    }

    public function testDestinationRuleAppliesToAllRefundAndWithdrawalOperations(): void
    {
        foreach (['/v1/withdrawals', '/v1/withdrawals/calculate', '/v1/payments/refund', '/v1/deposit-addresses/refund-blocked'] as $path) {
            try {
                Parameters::validate($path, ['display_total' => '1', 'asset_code' => 'USDT', 'merchant_order' => 'test-order-1', 'destination' => 'synthetic:address', 'fee_deducted' => false]);
                self::fail('Invalid destination accepted.');
            } catch (ConfigurationException $exception) {
                self::assertStringContainsString('Destination', $exception->getMessage());
            }
        }
    }

    public function testBooleanValuesMatchServerValidation(): void
    {
        foreach ([true, false, 0, 1, '0', '1'] as $boolean) {
            Parameters::validate('/v1/deposit-addresses/block', ['merchant_order' => 'test-order-1', 'force_refund' => $boolean]);
        }
        $this->expectException(ConfigurationException::class);
        Parameters::validate('/v1/deposit-addresses/block', ['merchant_order' => 'test-order-1', 'force_refund' => 'false']);
    }

    public function testOrderIdentifierLimitDependsOnOperation(): void
    {
        Parameters::validate('/v1/payments/lookup', ['merchant_order' => str_repeat('a', 128)]);
        $this->expectException(ConfigurationException::class);
        Parameters::validate('/v1/deposit-addresses/block', ['merchant_order' => str_repeat('a', 101)]);
    }
}
