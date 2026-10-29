<?php

declare(strict_types=1);

namespace SouthApi\Security;

use LogicException;

/** Prevent accidental credential disclosure through common debugging/serialization. */
trait Redacted
{
    /** @return array{state: string} */
    final public function __debugInfo(): array
    {
        return ['state' => '[REDACTED]'];
    }

    /** @return array<never, never> */
    final public function __serialize(): array
    {
        throw new LogicException('Serialization is disabled for sensitive SDK objects.');
    }

    /** @param array<mixed> $data */
    final public function __unserialize(#[\SensitiveParameter] array $data): void
    {
        throw new LogicException('Deserialization is disabled for sensitive SDK objects.');
    }
}
