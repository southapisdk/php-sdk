<?php

declare(strict_types=1);

namespace SouthApi\Responses;

use SouthApi\Security\Redacted;

/** Response content is merchant data: do not log it. */
class Result
{
    use Redacted;

    /** @param array<array-key, mixed>|null $content */
    public function __construct(#[\SensitiveParameter] private readonly ?array $content)
    {
    }

    /** @return array<array-key, mixed>|null */
    public function content(): ?array
    {
        return $this->content;
    }

    /** @return array<array-key, mixed> */
    public function arrayContent(): array
    {
        if ($this->content === null) {
            throw new \SouthApi\Exceptions\ProtocolException('Expected structured response content.');
        }
        return $this->content;
    }
}
