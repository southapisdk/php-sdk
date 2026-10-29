<?php

declare(strict_types=1);

namespace SouthApi\Resources;

use SouthApi\Responses\Page;
use SouthApi\Responses\Result;
use SouthApi\Security\KeyType;

/**
 * @phpstan-type Lookup array{payment_reference?: string|null, merchant_order?: string|null}
 * @phpstan-type Asset array{asset_code: string, chain_code?: string}
 * @phpstan-type Invoice array{display_total: string, asset_code: string, merchant_order: string, target_asset?: string|null, chain_code?: string|null, return_link?: string|null, success_link?: string|null, callback_link?: string|null, expires_in?: int|null, partial_allowed?: bool|0|1|'0'|'1'|null, fee_deducted?: int|null, payment_tolerance_rate?: float|int|numeric-string|null, accepted_assets?: list<Asset>|null, excluded_assets?: list<Asset>|null, metadata?: string|null, referral_code?: string|null, contact_address?: string|null, discount_rate?: int|null, refresh_existing?: bool|0|1|'0'|'1'|null, integration_name?: string|null, simulated_success?: bool|0|1|'0'|'1'|null, simulated_failure?: bool|0|1|'0'|'1'|null}
 */
final class Payments extends Resource
{
    /** @param Invoice $parameters */
    public function create(#[\SensitiveParameter] array $parameters): Result
    {
        return $this->session->json('POST', '/v1/payments', KeyType::Payment, $parameters);
    }

    /** @param Lookup $parameters */
    public function lookup(#[\SensitiveParameter] array $parameters): Result
    {
        return $this->session->json('POST', '/v1/payments/lookup', KeyType::Payment, $parameters);
    }

    /** @param array{created_from?: string|null, created_to?: string|null} $filters */
    public function search(#[\SensitiveParameter] array $filters = [], #[\SensitiveParameter] ?string $cursor = null): Page
    {
        return $this->session->page('/v1/payments/search', KeyType::Payment, $filters, $cursor);
    }

    /** @param Lookup $parameters */
    public function markPaid(#[\SensitiveParameter] array $parameters): Result
    {
        return $this->session->json('POST', '/v1/payments/mark-paid', KeyType::Payment, $parameters);
    }

    /** @param array{destination: string, fee_deducted: bool|0|1|'0'|'1', payment_reference?: string, merchant_order?: string, display_total?: string|null} $parameters */
    public function refund(#[\SensitiveParameter] array $parameters): Result
    {
        return $this->session->json('POST', '/v1/payments/refund', KeyType::Payout, $parameters);
    }

    public function qrCode(#[\SensitiveParameter] string $paymentReference): Result
    {
        return $this->session->json('POST', '/v1/payments/qr-code', KeyType::Payment, ['payment_reference' => $paymentReference]);
    }

    /** @param array{payment_reference?: string|null, merchant_order?: string|null, transaction_hash?: string|null} $parameters */
    public function retryCallback(#[\SensitiveParameter] array $parameters): Result
    {
        return $this->session->json('POST', '/v1/payments/callback/retry', KeyType::Payment, $parameters);
    }

    /** @param array{payment_reference?: string|null, merchant_order?: string|null, transaction_hash?: string|null} $parameters */
    public function retryCallbackV2(#[\SensitiveParameter] array $parameters): Result
    {
        return $this->session->json('POST', '/v2/payments/callback/retry', KeyType::Payment, $parameters);
    }

    public function options(): Result
    {
        return $this->session->json('POST', '/v1/payments/options', KeyType::Payment);
    }
}
