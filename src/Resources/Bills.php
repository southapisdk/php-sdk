<?php

declare(strict_types=1);

namespace SouthApi\Resources;

use SouthApi\Responses\{Result, Page};
use SouthApi\Security\KeyType;

final class Bills extends Resource
{
    /** @param array<string, mixed> $parameters Documented nested JSON payload. */
    public function create(#[\SensitiveParameter] array $parameters): Result
    {
        return $this->session->json('POST', '/api/v1/bills', KeyType::Payment, $parameters, []);
    }

    /** @param array<string, string|int> $filters */
    public function search(#[\SensitiveParameter] array $filters = []): Page
    {
        return $this->session->page('/api/v1/bills', KeyType::Payment, $filters);
    }

    public function lookup(#[\SensitiveParameter] string $reference): Result
    {
        return $this->session->json('GET', '/api/v1/bills/' . $this->segment($reference), KeyType::Payment, null, []);
    }

    public function pdf(#[\SensitiveParameter] string $reference): string
    {
        return $this->session->binary('/api/v1/bills/' . $this->segment($reference) . '/pdf', KeyType::Payment, 'application/pdf');
    }

    /** @param array{contacts?: array{contact_address?: string|null, support_address?: string|null}, use_merchant_contact?: bool} $parameters */
    public function deliver(#[\SensitiveParameter] string $reference, #[\SensitiveParameter] array $parameters = []): Result
    {
        return $this->session->json('POST', '/api/v1/bills/' . $this->segment($reference) . '/delivery', KeyType::Payment, $parameters, []);
    }

    /** @param array{contacts?: array{contact_address?: string|null, support_address?: string|null}, use_merchant_contact?: bool} $parameters */
    public function retryDelivery(#[\SensitiveParameter] string $reference, #[\SensitiveParameter] array $parameters = []): Result
    {
        return $this->session->json('POST', '/api/v1/bills/' . $this->segment($reference) . '/delivery/retry', KeyType::Payment, $parameters, []);
    }

    public function disable(#[\SensitiveParameter] string $reference): Result
    {
        return $this->session->json('POST', '/api/v1/bills/' . $this->segment($reference) . '/disable', KeyType::Payment, null, []);
    }

    public function extend(#[\SensitiveParameter] string $reference): Result
    {
        return $this->session->json('POST', '/api/v1/bills/' . $this->segment($reference) . '/extend', KeyType::Payment, null, []);
    }

}
