<?php

declare(strict_types=1);

namespace SouthApi\Http;

use JsonException;
use SouthApi\Configuration;
use SouthApi\Exceptions\ApiException;
use SouthApi\Exceptions\ConfigurationException;
use SouthApi\Exceptions\ProtocolException;
use SouthApi\Exceptions\TransportException;
use SouthApi\Responses\Page;
use SouthApi\Responses\Result;
use SouthApi\Security\Credentials;
use SouthApi\Security\KeyType;
use SouthApi\Security\Redacted;
use Throwable;

/** Internal shared executor; use the named resources exposed by Client. */
final class Session
{
    use Redacted;

    public function __construct(
        #[\SensitiveParameter] private readonly Credentials $credentials,
        private readonly Configuration $configuration,
        #[\SensitiveParameter] private readonly Transport $transport,
    ) {
    }

    /**
     * @param array<string, mixed>|null $parameters
     * @param array<string, string|int> $query
     */
    public function call(string $method, #[\SensitiveParameter] string $path, KeyType $key, #[\SensitiveParameter] ?array $parameters = null, #[\SensitiveParameter] array $query = [], ?string $format = null): Result|string
    {
        if (!preg_match('#\A/api/v1/[a-zA-Z0-9/_.%~-]+\z#', $path) || str_contains($path, '..')) {
            throw new ConfigurationException('Invalid API endpoint.');
        }
        if (!in_array($method, ['GET', 'POST', 'PUT'], true)) {
            throw new ConfigurationException('Invalid HTTP method.');
        }
        Parameters::validate($method, $path, $parameters, $query);
        try {
            $body = $parameters === null ? '' : json_encode($parameters === [] ? new \stdClass() : $parameters, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ConfigurationException('Request parameters cannot be encoded as JSON.');
        }
        $target = $path;
        if ($query !== []) {
            $target .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        $headers = ['Accept' => $format ?? 'application/json'];
        if ($parameters !== null) {
            $headers['Content-Type'] = 'application/json; charset=utf-8';
        }
        $headers += $this->credentials->headers($key, $body, $method, $target);
        $url = $this->configuration->baseUrl . $target;
        try {
            $response = $this->transport->send(new Request($method, $url, $headers, $body));
        } catch (ProtocolException) {
            throw new ProtocolException('API response rejected.');
        } catch (Throwable) {
            throw new TransportException('API transport failed; the operation outcome may be unknown.');
        }
        if (strlen($response->body) > $this->configuration->maxResponseBytes) {
            throw new ProtocolException('Response exceeds the configured size limit.');
        }
        if ($response->status >= 300 && $response->status < 400) {
            throw new ProtocolException('API redirects are not accepted.');
        }
        if ($format !== null && $response->status === 200) {
            return $response->body;
        }
        try {
            $data = json_decode($response->body, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw new ProtocolException('API returned an invalid JSON response.');
        }
        if (!is_array($data)) {
            throw new ProtocolException('API returned an invalid response envelope.');
        }
        if (!in_array($response->status, [200, 201], true)) {
            $error = $data['error'] ?? null;
            $code = is_array($error) ? ($error['code'] ?? null) : null;
            $safeCode = is_string($code) && in_array($code, [
                'authentication_failed', 'forbidden', 'not_found', 'validation_failed',
                'business_rule_failed', 'internal_error', 'bad_request', 'method_not_allowed', 'conflict', 'rate_limited',
            ], true) ? $code : 'api_error';
            throw new ApiException($response->status, $safeCode);
        }
        if (($data['outcome'] ?? null) !== 0 || !array_key_exists('content', $data) || !($data['content'] === null || is_array($data['content']))) {
            throw new ProtocolException('API returned an invalid success envelope.');
        }
        return new Result($data['content']);
    }

    /** @param array<string, mixed>|null $parameters
     * @param array<string, string|int> $query
     */
    public function json(string $method, #[\SensitiveParameter] string $path, KeyType $key, #[\SensitiveParameter] ?array $parameters = null, #[\SensitiveParameter] array $query = []): Result
    {
        $result = $this->call($method, $path, $key, $parameters, $query);
        if (!$result instanceof Result) {
            throw new ProtocolException('Expected a JSON response.');
        }
        return $result;
    }

    /** @param array<string, string|int> $filters */
    public function page(#[\SensitiveParameter] string $path, KeyType $key, #[\SensitiveParameter] array $filters = []): Page
    {
        return new Page($this->json('GET', $path, $key, query: $filters)->arrayContent());
    }

    public function binary(string $path, KeyType $key, string $format): string
    {
        $result = $this->call('GET', $path, $key, format: $format);
        if (!is_string($result)) {
            throw new ProtocolException('Expected a binary response.');
        }
        return $result;
    }
}
