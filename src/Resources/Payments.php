<?php

declare(strict_types=1);

namespace SouthApi\Resources;

use SouthApi\Responses\{Result, Page};
use SouthApi\Security\KeyType;

final class Payments extends Resource
{
    /** @param array<string, mixed> $parameters Documented nested JSON payload. */
    public function create(#[\SensitiveParameter] array $parameters): Result
    {
        return $this->session->json('POST', '/api/v1/charges', KeyType::Payment, $parameters, []);
    }

    /** @param array<string, string|int> $filters */
    public function search(#[\SensitiveParameter] array $filters = []): Page
    {
        return $this->session->page('/api/v1/charges', KeyType::Payment, $filters);
    }

    public function lookup(#[\SensitiveParameter] string $reference): Result
    {
        return $this->session->json('GET', '/api/v1/charges/' . $this->segment($reference), KeyType::Payment, null, []);
    }

    public function lookupByOrder(#[\SensitiveParameter] string $merchantOrder): Result
    {
        return $this->session->json('GET', '/api/v1/charges/by-order/' . $this->segment($merchantOrder), KeyType::Payment, null, []);
    }

    public function qrCode(#[\SensitiveParameter] string $paymentReference): Result
    {
        return $this->session->json('GET', '/api/v1/charges/' . $this->segment($paymentReference) . '/qr-code', KeyType::Payment, null, []);
    }

    public function retryCallback(#[\SensitiveParameter] string $paymentReference): Result
    {
        return $this->session->json('POST', '/api/v1/charges/' . $this->segment($paymentReference) . '/callbacks', KeyType::Payment, null, []);
    }

    public function markPaid(#[\SensitiveParameter] string $paymentReference): Result
    {
        return $this->session->json('POST', '/api/v1/charges/' . $this->segment($paymentReference) . '/mark-paid', KeyType::Payment, null, []);
    }

    public function retryCallbackByTransaction(#[\SensitiveParameter] string $transactionHash): Result
    {
        return $this->session->json('POST', '/api/v1/charges/callbacks', KeyType::Payment, ['transaction_hash' => $transactionHash], []);
    }

    /** @param array{destination: string, fee_deducted: bool, refund?: array{display_total?: string|null}} $parameters */
    public function refund(#[\SensitiveParameter] string $paymentReference, #[\SensitiveParameter] array $parameters): Result
    {
        return $this->session->json('POST', '/api/v1/charges/' . $this->segment($paymentReference) . '/refunds', KeyType::Payout, $parameters, []);
    }

    public function options(): Result
    {
        return $this->session->json('GET', '/api/v1/charge-options', KeyType::Payment, null, []);
    }

}
