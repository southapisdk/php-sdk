<?php

declare(strict_types=1);

namespace SouthApi\Resources;

use SouthApi\Responses\{Result, Page};
use SouthApi\Security\KeyType;

final class Transactions extends Resource
{
    /** @param array<string, string|int> $filters */
    public function search(#[\SensitiveParameter] array $filters = []): Page
    {
        return $this->session->page('/api/v1/ledger', KeyType::Payment, $filters);
    }

}
