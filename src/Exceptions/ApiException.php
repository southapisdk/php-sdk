<?php

declare(strict_types=1);

namespace SouthApi\Exceptions;

final class ApiException extends SdkException
{
    public function __construct(public readonly int $status, public readonly string $errorCode)
    {
        parent::__construct('API request failed (' . $errorCode . ').', $status);
    }
}
