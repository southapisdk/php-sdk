<?php

declare(strict_types=1);

namespace SouthApi\Tests;

use PHPUnit\Framework\TestCase;
use SouthApi\Client;
use SouthApi\Exceptions\{ApiException, ConfigurationException, ProtocolException};
use SouthApi\Http\{Parameters, Response};
use SouthApi\Responses\Page;
use SouthApi\Security\Credentials;

final class CurrentApiTest extends TestCase
{
    public function testPaginationUsesServerFlag(): void
    {
        $content = ['entries' => [], 'pagination' => ['item_count' => 0, 'has_more' => true, 'next_cursor' => null, 'previous_cursor' => 'previous', 'page_size' => 15]];
        self::assertTrue((new Page($content))->hasMore);
        $content['pagination']['has_more'] = false;
        $content['pagination']['next_cursor'] = 'next';
        self::assertFalse((new Page($content))->hasMore);
    }

    public function testBalancesUseDirectGroups(): void
    {
        $transport = new RecordingTransport(new Response(200, '{"outcome":0,"content":{"business":[{"available_balance":"123456789.00000001"}],"personal":[]}}'));
        $client = $this->client($transport);
        self::assertSame('123456789.00000001', $client->balances->get()->business()[0]['available_balance']);
        $transport->response = new Response(200, '{"outcome":0,"content":[{"balances":{"business":[],"personal":[]}}]}');
        $this->expectException(ProtocolException::class);
        $client->balances->get();
    }

    public function testNoResultActionHasEmptyBody(): void
    {
        $transport = new RecordingTransport(new Response(200, '{"outcome":0,"content":null}'));
        $result = $this->client($transport)->payments->retryCallback('test-ref');
        self::assertNull($result->content());
        self::assertSame('', $transport->requests[0]->body);
        self::assertArrayNotHasKey('Content-Type', $transport->requests[0]->headers);
        $this->expectException(ProtocolException::class);
        $result->arrayContent();
    }

    public function testCreatedResponsePreservesNestedValues(): void
    {
        $transport = new RecordingTransport(new Response(201, '{"outcome":0,"content":{"amounts":{"display_total":"1.00000001"},"chain":null,"created_at":"2026-01-01T00:00:00Z"}}'));
        self::assertSame(['amounts' => ['display_total' => '1.00000001'], 'chain' => null, 'created_at' => '2026-01-01T00:00:00Z'], $this->client($transport)->payments->create(['merchant_order' => 'order-1', 'pricing' => ['display_total' => '1', 'asset_code' => 'USD']])->content());
    }

    public function testEncodedQueryIsIncludedInSignature(): void
    {
        $transport = new RecordingTransport(new Response(200, '{"outcome":0,"content":{"entries":[],"pagination":{"item_count":0,"page_size":10,"has_more":false,"next_cursor":null,"previous_cursor":null}}}'));
        $this->client($transport)->payments->search(['cursor' => 'a+/= б', 'page_size' => 10]);
        $request = $transport->requests[0];
        $target = '/api/v1/charges?cursor=a%2B%2F%3D%20%D0%B1&page_size=10';
        self::assertStringEndsWith($target, $request->url);
        self::assertSame('', $request->body);
        self::assertSame(hash_hmac('sha256', $request->headers['X-Timestamp'] . '.GET.' . $target . '.', 'test-payment-key'), $request->headers['X-Signature']);
        self::assertNotSame(hash_hmac('sha256', $request->headers['X-Timestamp'] . '.POST.' . $target . '.', 'test-payment-key'), $request->headers['X-Signature']);
    }

    public function testPdfBytesArePreservedAndErrorsParsed(): void
    {
        $pdf = "%PDF-1.7\n\x00\xff\r\n";
        $transport = new RecordingTransport(new Response(200, $pdf, 'application/pdf'));
        $client = $this->client($transport);
        self::assertSame($pdf, $client->bills->pdf('test-ref'));
        self::assertSame('application/pdf', $transport->requests[0]->headers['Accept']);
        $transport->response = new Response(404, '{"outcome":1,"content":null,"error":{"code":"not_found","message":"private details"}}');
        try {
            $client->bills->pdf('test-ref');
            self::fail('Expected error.');
        } catch (ApiException $error) {
            self::assertStringNotContainsString('private details', $error->getMessage());
            self::assertCount(2, $transport->requests);
        }
    }

    public function testPayoutOrderLimitAndStrictBoolean(): void
    {
        $values = ['merchant_order' => str_repeat('a', 100), 'amount' => ['display_total' => '1', 'asset_code' => 'USDT'], 'chain' => ['destination' => 'synthetic-address'], 'fee_deducted' => false, 'priority_tier' => 'low'];
        Parameters::validate('POST', '/api/v1/cashouts', $values, []);
        foreach (['merchant_order' => str_repeat('a', 101), 'fee_deducted' => '0'] as $field => $invalid) {
            try {
                Parameters::validate('POST', '/api/v1/cashouts', array_replace($values, [$field => $invalid]), []);
                self::fail('Invalid parameter accepted.');
            } catch (ConfigurationException $error) {
                self::assertSame('Request parameters do not match the API contract.', $error->getMessage());
            }
        }
    }

    public function testInvalidFiltersFailBeforeTransport(): void
    {
        $transport = new RecordingTransport();
        foreach ([['page_size' => 0], ['page_size' => 101], ['unknown' => 'secret'], ['created_from' => '2026-01-01 10:00:00']] as $filters) {
            try {
                $this->client($transport)->payments->search($filters);
                self::fail('Invalid filter accepted.');
            } catch (ConfigurationException) {
                self::assertCount(0, $transport->requests);
            }
        }
    }

    public function testInvalidDestinationFailsBeforeTransport(): void
    {
        $transport = new RecordingTransport();
        foreach (['short', 'synthetic.address', 'synthetic:address', 'synthetic address', str_repeat('a', 129)] as $destination) {
            try {
                $this->client($transport)->withdrawals->calculate(['amount' => ['display_total' => '1', 'asset_code' => 'USDT'], 'chain' => ['destination' => $destination], 'fee_deducted' => false]);
                self::fail('Invalid destination accepted.');
            } catch (ConfigurationException $error) {
                self::assertCount(0, $transport->requests);
                self::assertStringNotContainsString($destination, $error->getMessage());
            }
        }
    }

    public function testDocumentedErrorCodesAreSafeAndNeverRetried(): void
    {
        foreach (['bad_request' => 400, 'authentication_failed' => 401, 'forbidden' => 403,
            'not_found' => 404, 'method_not_allowed' => 405, 'conflict' => 409,
            'validation_failed' => 422, 'business_rule_failed' => 422,
            'rate_limited' => 429, 'internal_error' => 500] as $code => $status) {
            $body = json_encode(['outcome' => 1, 'content' => null, 'error' => [
                'code' => $code, 'message' => 'private-server-data',
                'details' => ['fields' => ['pricing.display_total' => ['private-server-data']]],
            ]], JSON_THROW_ON_ERROR);
            $transport = new RecordingTransport(new Response($status, $body));
            try {
                $this->client($transport)->payments->lookup('test-ref');
                self::fail('Expected API error.');
            } catch (ApiException $error) {
                self::assertSame($status, $error->status);
                self::assertSame($code, $error->errorCode);
                self::assertStringNotContainsString('private-server-data', $error->getMessage());
                self::assertNull($error->getPrevious());
                self::assertCount(1, $transport->requests);
            }
        }
    }

    private function client(RecordingTransport $transport): Client
    {
        return new Client(new Credentials('00000000-0000-4000-8000-000000000000', 'test-payment-key', 'test-payout-key'), transport: $transport);
    }
}
