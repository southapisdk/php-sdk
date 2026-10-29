<?php

declare(strict_types=1);

namespace SouthApi\Resources;

use SouthApi\Responses\Page;
use SouthApi\Security\KeyType;

final class Transactions extends Resource
{
    public function search(#[\SensitiveParameter] ?string $cursor = null): Page
    {
        return $this->session->page('/v1/transactions/search', KeyType::Payment, [], $cursor);
    }
}
