<?php

declare(strict_types=1);

namespace SouthApi\Http;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use SouthApi\Configuration;
use SouthApi\Exceptions\ProtocolException;
use SouthApi\Exceptions\TransportException;
use SouthApi\Security\Redacted;
use Throwable;

final class GuzzleTransport implements Transport
{
    use Redacted;

    private readonly ?ClientInterface $http;

    /** An injected HTTP client is trusted application code, including its middleware. */
    public function __construct(private readonly Configuration $configuration, ?ClientInterface $http = null)
    {
        $this->http = $http;
    }

    public function send(#[\SensitiveParameter] Request $request): Response
    {
        if (!str_starts_with($request->url, $this->configuration->baseUrl . '/')) {
            throw new TransportException('Request destination is not the configured origin.');
        }
        $memory = fopen('php://memory', 'w+');
        if ($memory === false) {
            throw new TransportException('Cannot allocate response buffer.');
        }
        $buffer = Utils::streamFor($memory);
        $received = 0;
        $exceeded = false;
        $sink = FnStream::decorate($buffer, ['write' => function (#[\SensitiveParameter] string $chunk) use ($buffer, &$received, &$exceeded): int {
            $received += strlen($chunk);
            if ($received > $this->configuration->maxResponseBytes) {
                $exceeded = true;
                throw new ProtocolException('Response exceeds the configured size limit.');
            }
            return $buffer->write($chunk);
        }]);
        try {
            $http = $this->http ?? new Client(['handler' => HandlerStack::create(new CurlHandler(['handle_factory' => new SingleAttemptCurlFactory()]))]);
            $response = $http->request($request->method, $request->url, [
                'headers' => $request->headers,
                'body' => $request->body,
                'verify' => true,
                'allow_redirects' => false,
                'http_errors' => false,
                'debug' => false,
                'cookies' => false,
                'stream' => false,
                'sink' => $sink,
                'connect_timeout' => $this->configuration->connectTimeout,
                'timeout' => $this->configuration->requestTimeout,
            ]);
            $stream = $response->getBody();
            try {
                if ($stream->isSeekable()) {
                    $stream->rewind();
                }
                $body = '';
                while (true) {
                    $chunk = $stream->read(min(8192, $this->configuration->maxResponseBytes + 1 - strlen($body)));
                    if ($chunk === '') {
                        break;
                    }
                    $body .= $chunk;
                    if (strlen($body) > $this->configuration->maxResponseBytes) {
                        throw new ProtocolException('Response exceeds the configured size limit.');
                    }
                }
            } finally {
                $stream->close();
            }
            return new Response($response->getStatusCode(), $body);
        } catch (ProtocolException) {
            throw new ProtocolException('Response exceeds the configured size limit.');
        } catch (Throwable) {
            if ($exceeded) {
                throw new ProtocolException('Response exceeds the configured size limit.');
            }
            throw new TransportException('API transport failed; the operation outcome may be unknown.');
        } finally {
            $sink->close();
        }
    }
}
