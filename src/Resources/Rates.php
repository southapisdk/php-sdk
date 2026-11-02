<?php

declare(strict_types=1);

namespace SouthApi\Resources;

use SouthApi\Responses\{Result, Page};
use SouthApi\Security\KeyType;

final class Rates extends Resource
{
    public function all(): Result
    {
        return $this->session->json('GET', '/api/v1/fx-rates', KeyType::None, null, []);
    }

    public function get(#[\SensitiveParameter] string $assetCode): Result
    {
        return $this->session->json('GET', '/api/v1/fx-rates/' . $this->segment($assetCode), KeyType::None, null, []);
    }

    public function xml(): string
    {
        return $this->session->binary('/api/v1/fx-rates.xml', KeyType::None, 'application/xml');
    }

}
