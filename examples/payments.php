<?php

declare(strict_types=1);

use SouthApi\Client;
use SouthApi\Responses\Result;

/** Define integration functions without running live calls on include. */
/** Creation can return a conflict; do not automatically retry financial operations. */
function createExampleInvoice(Client $client, string $orderId): Result
{
    return $client->payments->create([
        'pricing' => ['display_total' => '25.00000001', 'asset_code' => 'USD'],
        'merchant_order' => $orderId,
        'links' => ['callback_link' => 'https://merchant.example/webhooks/southapi'],
    ]);
}

function lookupExampleInvoice(Client $client, string $orderId): Result
{
    return $client->payments->lookupByOrder($orderId);
}
