<?php

declare(strict_types=1);

namespace SouthApi\Resources;

use SouthApi\Responses\Result;
use SouthApi\Security\KeyType;

final class Settings extends Resource
{
    public function paymentAssets(): Result
    {
        return $this->session->json('POST', '/v1/settings/payment-assets', KeyType::Payment);
    }

    public function discounts(): Result
    {
        return $this->session->json('POST', '/v1/payment-discounts/search', KeyType::Payment);
    }

    public function setDiscount(#[\SensitiveParameter] string $assetCode, #[\SensitiveParameter] string $chainCode, int $discountRate): Result
    {
        return $this->session->json('POST', '/v1/payment-discounts/update', KeyType::Payment, ['asset_code' => $assetCode, 'chain_code' => $chainCode, 'discount_rate' => $discountRate]);
    }
}
