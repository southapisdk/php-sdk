<?php

declare(strict_types=1);

namespace SouthApi\Resources;

use SouthApi\Responses\{Result, Page};
use SouthApi\Security\KeyType;

final class DepositAddresses extends Resource
{
    /** @param array<string, mixed> $parameters Documented nested JSON payload. */
    public function create(#[\SensitiveParameter] array $parameters): Result
    {
        return $this->session->json('POST', '/api/v1/receiving-addresses', KeyType::Payment, $parameters, []);
    }

    /** @param array<string, string|int> $filters */
    public function search(#[\SensitiveParameter] array $filters = []): Page
    {
        return $this->session->page('/api/v1/receiving-addresses', KeyType::Payment, $filters);
    }

    public function lookup(#[\SensitiveParameter] string $reference): Result
    {
        return $this->session->json('GET', '/api/v1/receiving-addresses/' . $this->segment($reference), KeyType::Payment, null, []);
    }

    public function lookupByOrder(#[\SensitiveParameter] string $merchantOrder): Result
    {
        return $this->session->json('GET', '/api/v1/receiving-addresses/by-order/' . $this->segment($merchantOrder), KeyType::Payment, null, []);
    }

    public function qrCode(#[\SensitiveParameter] string $reference): Result
    {
        return $this->session->json('GET', '/api/v1/receiving-addresses/' . $this->segment($reference) . '/qr-code', KeyType::Payment, null, []);
    }

    /** @param array<string, string|int> $filters */
    public function payments(#[\SensitiveParameter] string $reference, #[\SensitiveParameter] array $filters = []): Page
    {
        return $this->session->page('/api/v1/receiving-addresses/' . $this->segment($reference) . '/charges', KeyType::Payment, $filters);
    }

    public function block(#[\SensitiveParameter] string $reference, bool $forceRefund = false): Result
    {
        return $this->session->json('POST', '/api/v1/receiving-addresses/' . $this->segment($reference) . '/block', KeyType::Payment, ['force_refund' => $forceRefund], []);
    }

    public function refundBlocked(#[\SensitiveParameter] string $reference, #[\SensitiveParameter] string $destination): Result
    {
        return $this->session->json('POST', '/api/v1/receiving-addresses/' . $this->segment($reference) . '/refund-blocked', KeyType::Payment, ['destination' => $destination], []);
    }

    /** @param array<string, string|int> $filters */
    public function paymentsByOrder(#[\SensitiveParameter] string $merchantOrder, #[\SensitiveParameter] array $filters = []): Page
    {
        return $this->session->page('/api/v1/receiving-addresses/by-order/' . $this->segment($merchantOrder) . '/charges', KeyType::Payment, $filters);
    }

    public function blockByOrder(#[\SensitiveParameter] string $merchantOrder, bool $forceRefund = false): Result
    {
        return $this->session->json('POST', '/api/v1/receiving-addresses/by-order/' . $this->segment($merchantOrder) . '/block', KeyType::Payment, ['force_refund' => $forceRefund], []);
    }

    public function refundBlockedByOrder(#[\SensitiveParameter] string $merchantOrder, #[\SensitiveParameter] string $destination): Result
    {
        return $this->session->json('POST', '/api/v1/receiving-addresses/by-order/' . $this->segment($merchantOrder) . '/refund-blocked', KeyType::Payment, ['destination' => $destination], []);
    }

}
