<?php

declare(strict_types=1);

namespace SouthApi\Resources;

use SouthApi\Responses\Page;
use SouthApi\Responses\Result;
use SouthApi\Security\KeyType;

/** @phpstan-type Lookup array{deposit_address_reference?: string, merchant_order?: string} */
final class DepositAddresses extends Resource
{
    /** @param array{asset_code: string, chain_code: string, merchant_order: string, callback_link?: string|null, referral_code?: string|null} $parameters */
    public function create(#[\SensitiveParameter] array $parameters): Result
    {
        return $this->session->json('POST', '/v1/deposit-addresses', KeyType::Payment, $parameters);
    }

    public function qrCode(#[\SensitiveParameter] string $depositAddressReference): Result
    {
        return $this->session->json('POST', '/v1/deposit-addresses/qr-code', KeyType::Payment, ['deposit_address_reference' => $depositAddressReference]);
    }

    /** @param array{deposit_address_reference?: string, merchant_order?: string, force_refund?: bool|0|1|'0'|'1'} $parameters */
    public function block(#[\SensitiveParameter] array $parameters): Result
    {
        return $this->session->json('POST', '/v1/deposit-addresses/block', KeyType::Payment, $parameters);
    }

    /** @param array{destination: string, deposit_address_reference?: string, merchant_order?: string} $parameters */
    public function refundBlocked(#[\SensitiveParameter] array $parameters): Result
    {
        return $this->session->json('POST', '/v1/deposit-addresses/refund-blocked', KeyType::Payment, $parameters);
    }

    /** @param Lookup $parameters */
    public function payments(#[\SensitiveParameter] array $parameters, #[\SensitiveParameter] ?string $cursor = null): Page
    {
        return $this->session->page('/v1/deposit-addresses/payments/search', KeyType::Payment, $parameters, $cursor);
    }

    public function search(#[\SensitiveParameter] ?string $cursor = null): Page
    {
        return $this->session->page('/v1/deposit-addresses/search', KeyType::Payment, [], $cursor);
    }
}
