<?php

declare(strict_types=1);

namespace SouthApi\Resources;

use SouthApi\Exceptions\ProtocolException;
use SouthApi\Responses\Page;
use SouthApi\Responses\Result;
use SouthApi\Security\KeyType;

/**
 * @phpstan-type Withdrawal array{display_total: string, asset_code: string, merchant_order: string, destination: string, fee_deducted: bool|0|1|'0'|'1', target_asset?: string|null, chain_code?: string|null, callback_link?: string|null, rate_source?: string|null, source_asset?: string|null, memo?: string|null, priority_tier?: string|null, simulated_success?: bool|0|1|'0'|'1'|null, simulated_failure?: bool|0|1|'0'|'1'|null}
 * @phpstan-type Calculation array{display_total: string, asset_code: string, destination: string, fee_deducted: bool|0|1|'0'|'1', target_asset?: string|null, chain_code?: string|null, rate_source?: string|null, priority_tier?: string|null}
 */
final class Withdrawals extends Resource
{
    /** @param Withdrawal $parameters */
    public function create(#[\SensitiveParameter] array $parameters): Result
    {
        return $this->session->json('POST', '/v1/withdrawals', KeyType::Payout, $parameters);
    }

    /** @param Calculation $parameters */
    public function calculate(#[\SensitiveParameter] array $parameters): Result
    {
        return $this->session->json('POST', '/v1/withdrawals/calculate', KeyType::Payout, $parameters);
    }

    /** @param array{withdrawal_reference?: string|null, merchant_order?: string|null} $parameters */
    public function lookup(#[\SensitiveParameter] array $parameters): Result
    {
        return $this->session->json('POST', '/v1/withdrawals/lookup', KeyType::Payout, $parameters);
    }

    /** @param array{created_from?: string|null, created_to?: string|null} $filters */
    public function search(#[\SensitiveParameter] array $filters = [], #[\SensitiveParameter] ?string $cursor = null): Page
    {
        return $this->session->page('/v1/withdrawals/search', KeyType::Payout, $filters, $cursor);
    }

    public function options(): Result
    {
        return $this->session->json('POST', '/v1/withdrawals/options', KeyType::Payout);
    }

    public function priority(#[\SensitiveParameter] string $assetCode, #[\SensitiveParameter] string $chainCode): Result
    {
        $result = $this->session->call('GET', '/v1/withdrawals/priority', KeyType::Payout, [], ['asset_code' => $assetCode, 'chain_code' => $chainCode]);
        if (!$result instanceof Result) {
            throw new ProtocolException('Expected a JSON response.');
        }
        return $result;
    }
}
