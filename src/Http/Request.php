<?php

declare(strict_types=1);

namespace SouthApi\Http;

use SouthApi\Security\Redacted;

/** Transport input. Custom transports are trusted to handle these sensitive values. */
final readonly class Request
{
    use Redacted;

    /** @param array<string, string> $headers */
    public function __construct(
        public string $method,
        #[\SensitiveParameter] public string $url,
        #[\SensitiveParameter] public array $headers,
        #[\SensitiveParameter] public string $body,
    ) {
    }
}
