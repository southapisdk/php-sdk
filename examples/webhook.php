<?php

declare(strict_types=1);

use SouthApi\Security\WebhookVerifier;

/**
 * Call from your HTTP handler with the original raw bytes and header values.
 * $enqueue must durably accept the event or throw. Return HTTP 200 only on success.
 * Deduplicate processing using the documented reference and payment_phase.
 * @param callable(array<array-key, mixed>): void $enqueue
 */
function acceptSouthApiWebhook(
    WebhookVerifier $verifier,
    #[\SensitiveParameter] string $rawBody,
    string $timestamp,
    #[\SensitiveParameter] string $signature,
    callable $enqueue,
): int {
    $event = $verifier->verify($rawBody, $timestamp, $signature);
    $enqueue($event->content());
    return 200;
}
