<?php

declare(strict_types=1);

use SouthApi\Client;
use SouthApi\Responses\Result;

/** Define integration functions without running live calls on include. */
/** refresh_existing can reuse the existing address: inspect the returned result. */
function createExampleInvoice(Client $client, string $orderId): Result
{
    return $client->payments->create([
        'display_total' => '25.00000001',
        'asset_code' => 'USD',
        'merchant_order' => $orderId,
        'callback_link' => 'https://merchant.example/webhooks/southapi',
    ]);
}

function lookupExampleInvoice(Client $client, string $orderId): Result
{
    return $client->payments->lookup(['merchant_order' => $orderId]);
}
