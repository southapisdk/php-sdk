<?php

declare(strict_types=1);

namespace SouthApi\Security;

use Closure;
use JsonException;
use SouthApi\Exceptions\ConfigurationException;
use SouthApi\Exceptions\WebhookException;
use SouthApi\Responses\Result;

final class WebhookVerifier
{
    use Redacted;

    /** @var Closure(): int */
    private readonly Closure $clock;

    /** @param (Closure(): int)|null $clock */
    public function __construct(
        #[\SensitiveParameter] private readonly Credentials $credentials,
        ?Closure $clock = null,
        private readonly int $maxBodyBytes = 1_048_576,
    ) {
        if ($maxBodyBytes < 1) {
            throw new ConfigurationException('Webhook body limit must be positive.');
        }
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function verify(#[\SensitiveParameter] string $rawBody, string $timestamp, #[\SensitiveParameter] string $signature): Result
    {
        if (strlen($rawBody) > $this->maxBodyBytes
            || !preg_match('/\A[0-9]{1,12}\z/', $timestamp)
            || !preg_match('/\A[0-9a-f]{64}\z/', $signature)
            || abs(($this->clock)() - (int) $timestamp) > 300) {
            throw new WebhookException('Webhook authentication failed.');
        }
        try {
            $payload = json_decode($rawBody, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw new WebhookException('Webhook authentication failed.');
        }
        if (!is_array($payload)) {
            throw new WebhookException('Webhook authentication failed.');
        }
        $type = match ($payload['event_kind'] ?? null) {
            'payment', 'deposit' => KeyType::Payment,
            'withdrawal', 'blocked_address_payment' => KeyType::Payout,
            default => null,
        };
        if ($type === null) {
            throw new WebhookException('Webhook authentication failed.');
        }
        try {
            $expected = $this->credentials->webhookSignature($type, $timestamp, $rawBody);
        } catch (ConfigurationException) {
            throw new WebhookException('Webhook authentication failed.');
        }
        if (!hash_equals($expected, $signature)) {
            throw new WebhookException('Webhook authentication failed.');
        }
        return new Result($payload);
    }
}
