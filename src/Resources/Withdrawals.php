<?php

declare(strict_types=1);

namespace SouthApi\Resources;

use SouthApi\Responses\{Result, Page};
use SouthApi\Security\KeyType;

final class Withdrawals extends Resource
{
    /** @param array<string, mixed> $parameters Documented nested JSON payload. */
    public function create(#[\SensitiveParameter] array $parameters): Result
    {
        return $this->session->json('POST', '/api/v1/cashouts', KeyType::Payout, $parameters, []);
    }

    /** @param array<string, mixed> $parameters Documented nested JSON payload. */
    public function calculate(#[\SensitiveParameter] array $parameters): Result
    {
        return $this->session->json('POST', '/api/v1/cashouts/quote', KeyType::Payout, $parameters, []);
    }

    /** @param array<string, string|int> $filters */
    public function search(#[\SensitiveParameter] array $filters = []): Page
    {
        return $this->session->page('/api/v1/cashouts', KeyType::Payout, $filters);
    }

    public function lookup(#[\SensitiveParameter] string $reference): Result
    {
        return $this->session->json('GET', '/api/v1/cashouts/' . $this->segment($reference), KeyType::Payout, null, []);
    }

    public function lookupByOrder(#[\SensitiveParameter] string $merchantOrder): Result
    {
        return $this->session->json('GET', '/api/v1/cashouts/by-order/' . $this->segment($merchantOrder), KeyType::Payout, null, []);
    }

    public function cancel(#[\SensitiveParameter] string $reference): Result
    {
        return $this->session->json('POST', '/api/v1/cashouts/' . $this->segment($reference) . '/cancel', KeyType::Payout, null, []);
    }

    public function cancelByOrder(#[\SensitiveParameter] string $merchantOrder): Result
    {
        return $this->session->json('POST', '/api/v1/cashouts/by-order/' . $this->segment($merchantOrder) . '/cancel', KeyType::Payout, null, []);
    }

    public function options(): Result
    {
        return $this->session->json('GET', '/api/v1/cashout-options', KeyType::Payout, null, []);
    }

    public function priority(#[\SensitiveParameter] string $assetCode, #[\SensitiveParameter] string $chainCode): Result
    {
        return $this->session->json('GET', '/api/v1/cashout-options/' . $this->segment($assetCode) . '/' . $this->segment($chainCode) . '/priority', KeyType::Payout, null, []);
    }

}
