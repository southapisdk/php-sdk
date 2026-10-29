<?php

declare(strict_types=1);

namespace SouthApi\Http;

use SouthApi\Security\Redacted;

final readonly class Response
{
    use Redacted;

    public function __construct(public int $status, #[\SensitiveParameter] public string $body)
    {
    }
}
