<?php

declare(strict_types=1);

namespace SouthApi\Resources;

use SouthApi\Responses\Result;
use SouthApi\Security\KeyType;

final class Transfers extends Resource
{
    public function toPersonal(#[\SensitiveParameter] string $displayTotal, #[\SensitiveParameter] string $assetCode): Result
    {
        return $this->session->json('POST', '/v1/transfers/to-personal', KeyType::Payout, ['display_total' => $displayTotal, 'asset_code' => $assetCode]);
    }

    public function toBusiness(#[\SensitiveParameter] string $displayTotal, #[\SensitiveParameter] string $assetCode): Result
    {
        return $this->session->json('POST', '/v1/transfers/to-business', KeyType::Payout, ['display_total' => $displayTotal, 'asset_code' => $assetCode]);
    }
}
