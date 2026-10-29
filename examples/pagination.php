<?php

declare(strict_types=1);

use SouthApi\Client;
use SouthApi\Responses\Page;

/** The caller explicitly requests one page; pass its nextCursor to request another. */
function examplePaymentPage(Client $client, ?string $cursor = null): Page
{
    return $client->payments->search(['created_from' => '2026-01-01 00:00:00'], $cursor);
}
