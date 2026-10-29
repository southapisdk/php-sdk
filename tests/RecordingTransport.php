<?php

declare(strict_types=1);

namespace SouthApi\Tests;

use SouthApi\Http\{Request, Response, Transport};
use Throwable;

final class RecordingTransport implements Transport
{
    /** @var list<Request> */
    public array $requests = [];

    public function __construct(public Response|Throwable $response = new Response(200, '{"outcome":0,"content":[[]]}'))
    {
    }

    public function send(#[\SensitiveParameter] Request $request): Response
    {
        $this->requests[] = $request;
        if ($this->response instanceof Throwable) {
            throw $this->response;
        }
        return $this->response;
    }
}
