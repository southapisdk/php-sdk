<?php

declare(strict_types=1);

namespace SouthApi\Http;

interface Transport
{
    public function send(#[\SensitiveParameter] Request $request): Response;
}
