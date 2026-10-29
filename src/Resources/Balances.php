<?php

declare(strict_types=1);

namespace SouthApi\Resources;

use SouthApi\Responses\BalanceResult;
use SouthApi\Security\KeyType;

final class Balances extends Resource
{
    public function get(): BalanceResult
    {
        return new BalanceResult($this->session->json('POST', '/v1/balances', KeyType::Payment)->content());
    }
}
