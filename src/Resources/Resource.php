<?php

declare(strict_types=1);

namespace SouthApi\Resources;

use SouthApi\Http\Session;
use SouthApi\Security\Redacted;

abstract class Resource
{
    use Redacted;

    public function __construct(#[\SensitiveParameter] protected readonly Session $session)
    {
    }
}
