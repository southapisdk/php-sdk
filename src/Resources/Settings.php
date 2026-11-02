<?php

declare(strict_types=1);

namespace SouthApi\Resources;

use SouthApi\Responses\{Result, Page};
use SouthApi\Security\KeyType;

final class Settings extends Resource
{
    public function paymentAssets(): Result
    {
        return $this->session->json('GET', '/api/v1/settings/charge-assets', KeyType::Payment, null, []);
    }

    public function discounts(): Result
    {
        return $this->session->json('GET', '/api/v1/charge-discounts', KeyType::Payment, null, []);
    }

    public function setDiscount(#[\SensitiveParameter] string $assetCode, #[\SensitiveParameter] string $chainCode, int $discountRate): Result
    {
        return $this->session->json('PUT', '/api/v1/charge-discounts/' . $this->segment($assetCode) . '/' . $this->segment($chainCode), KeyType::Payment, ['discount_rate' => $discountRate], []);
    }

}
