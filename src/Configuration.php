<?php

declare(strict_types=1);

namespace SouthApi;

use SouthApi\Exceptions\ConfigurationException;

final readonly class Configuration
{
    public string $baseUrl;

    public function __construct(
        #[\SensitiveParameter] string $baseUrl = 'https://api.southapi.com',
        public float $connectTimeout = 5.0,
        public float $requestTimeout = 30.0,
        public int $maxResponseBytes = 10_485_760,
    ) {
        $parts = parse_url($baseUrl);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https'
            || !isset($parts['host']) || $parts['host'] === ''
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || !in_array($parts['path'] ?? '', ['', '/'], true)
            || filter_var($baseUrl, FILTER_VALIDATE_URL) === false
            || preg_match('/[\x00-\x20\x7f\\\\]/', $baseUrl)
        ) {
            throw new ConfigurationException('Base URL must be an HTTPS origin without credentials, path, query or fragment.');
        }
        if (!is_finite($connectTimeout) || !is_finite($requestTimeout)
            || $connectTimeout <= 0 || $requestTimeout <= 0 || $maxResponseBytes < 1) {
            throw new ConfigurationException('Timeouts and response limit must be positive and finite.');
        }
        $this->baseUrl = rtrim($baseUrl, '/');
    }
}
