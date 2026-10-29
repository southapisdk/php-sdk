<?php

declare(strict_types=1);

use SouthApi\Client;
use SouthApi\Configuration;
use SouthApi\Security\Credentials;

/** Supply values from your application's secret store; the SDK does not read .env. */
function configureSouthApi(
    #[\SensitiveParameter] string $merchantId,
    #[\SensitiveParameter] string $paymentKey,
    #[\SensitiveParameter] ?string $payoutKey = null,
): Client {
    return new Client(
        new Credentials($merchantId, $paymentKey, $payoutKey),
        new Configuration(connectTimeout: 5, requestTimeout: 30),
    );
}
