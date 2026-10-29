<?php

declare(strict_types=1);

namespace SouthApi\Http;

use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\CurlFactoryInterface;
use GuzzleHttp\Handler\EasyHandle;
use Psr\Http\Message\RequestInterface;
use SouthApi\Exceptions\TransportException;

/** One factory per send prevents Guzzle's internal rewind retry from sending twice. */
final class SingleAttemptCurlFactory implements CurlFactoryInterface
{
    private bool $attempted = false;
    private readonly CurlFactory $factory;

    public function __construct()
    {
        $this->factory = new CurlFactory(0);
    }

    /** @param array<string, mixed> $options */
    public function create(#[\SensitiveParameter] RequestInterface $request, #[\SensitiveParameter] array $options): EasyHandle
    {
        if ($this->attempted) {
            throw new TransportException('Automatic transport retries are disabled.');
        }
        $this->attempted = true;
        return $this->factory->create($request, $options);
    }

    public function release(#[\SensitiveParameter] EasyHandle $easy): void
    {
        $this->factory->release($easy);
    }
}
