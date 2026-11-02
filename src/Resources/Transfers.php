<?php

declare(strict_types=1);

namespace SouthApi\Resources;

use SouthApi\Responses\{Result, Page};
use SouthApi\Security\KeyType;

final class Transfers extends Resource
{
    public function toPersonal(#[\SensitiveParameter] string $displayTotal, #[\SensitiveParameter] string $assetCode): Result
    {
        return $this->session->json('POST', '/api/v1/fund-transfers', KeyType::Payout, ['direction' => 'to_personal', 'amount' => ['display_total' => $displayTotal, 'asset_code' => $assetCode]], []);
    }

    public function toBusiness(#[\SensitiveParameter] string $displayTotal, #[\SensitiveParameter] string $assetCode): Result
    {
        return $this->session->json('POST', '/api/v1/fund-transfers', KeyType::Payout, ['direction' => 'to_business', 'amount' => ['display_total' => $displayTotal, 'asset_code' => $assetCode]], []);
    }

}
