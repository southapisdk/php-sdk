<?php

declare(strict_types=1);

namespace SouthApi\Resources;

use SouthApi\Exceptions\ProtocolException;
use SouthApi\Responses\Result;
use SouthApi\Security\KeyType;

final class Rates extends Resource
{
    public function get(#[\SensitiveParameter] string $assetCode): Result
    {
        return $this->session->json('GET', '/v1/rates/' . rawurlencode($assetCode), KeyType::None);
    }

    public function all(): Result
    {
        return $this->session->json('GET', '/v2/rates', KeyType::None);
    }

    public function dictionary(#[\SensitiveParameter] string $assetCode): Result
    {
        return $this->session->json('GET', '/v2/rates/' . rawurlencode($assetCode), KeyType::None);
    }

    public function xml(): string
    {
        $result = $this->session->call('GET', '/v1/rates.xml', KeyType::None, xml: true);
        if (!is_string($result)) {
            throw new ProtocolException('Expected an XML response.');
        }
        return $result;
    }
}
