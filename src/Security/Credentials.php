<?php

declare(strict_types=1);

namespace SouthApi\Security;

use Closure;
use SouthApi\Exceptions\ConfigurationException;

final class Credentials
{
    use Redacted;

    /** @var Closure(): int */
    private readonly Closure $clock;

    /** @param (Closure(): int)|null $clock */
    public function __construct(
        #[\SensitiveParameter] private readonly ?string $merchantId = null,
        #[\SensitiveParameter] private readonly ?string $paymentKey = null,
        #[\SensitiveParameter] private readonly ?string $payoutKey = null,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
        if ($merchantId !== null && !preg_match('/\A[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}\z/', $merchantId)) {
            throw new ConfigurationException('Merchant identifier must be a UUID.');
        }
    }

    /** @return array<string, string> */
    public function headers(KeyType $type, #[\SensitiveParameter] string $body): array
    {
        if ($type === KeyType::None) {
            return [];
        }
        if ($this->merchantId === null) {
            throw new ConfigurationException('A merchant identifier is required.');
        }
        $now = ($this->clock)();
        if ($now < 1) {
            throw new ConfigurationException('Request clock must return a positive Unix timestamp.');
        }
        $timestamp = (string) $now;
        return [
            'merchant' => $this->merchantId,
            'X-Timestamp' => $timestamp,
            'X-Signature' => $this->signature($type, $timestamp, $body),
        ];
    }

    public function webhookSignature(KeyType $type, string $timestamp, #[\SensitiveParameter] string $body): string
    {
        return $this->signature($type, $timestamp, $body);
    }

    private function signature(KeyType $type, string $timestamp, #[\SensitiveParameter] string $body): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $body, $this->key($type));
    }

    private function key(KeyType $type): string
    {
        $key = match ($type) {
            KeyType::Payment => $this->paymentKey,
            KeyType::Payout => $this->payoutKey,
            KeyType::None => null,
        };
        if ($key === null || $key === '') {
            throw new ConfigurationException('The required API key is not configured.');
        }
        return $key;
    }
}
