<?php

declare(strict_types=1);

namespace SouthApi\Http;

use SouthApi\Exceptions\ConfigurationException;

/** Minimal wire validation; business rules and detailed limits remain server-owned. */
final class Parameters
{
    /** @param array<string, mixed> $parameters */
    public static function validate(string $path, #[\SensitiveParameter] array $parameters): void
    {
        $required = match ($path) {
            '/v1/payments' => ['display_total', 'asset_code', 'merchant_order'],
            '/v1/withdrawals' => ['display_total', 'asset_code', 'merchant_order', 'destination', 'fee_deducted'],
            '/v1/withdrawals/calculate' => ['display_total', 'asset_code', 'destination', 'fee_deducted'],
            '/v1/deposit-addresses' => ['asset_code', 'chain_code', 'merchant_order'],
            '/v1/payments/refund' => ['destination', 'fee_deducted'],
            '/v1/deposit-addresses/refund-blocked' => ['destination'],
            '/v1/payments/qr-code' => ['payment_reference'],
            '/v1/deposit-addresses/qr-code' => ['deposit_address_reference'],
            '/v1/transfers/to-personal', '/v1/transfers/to-business' => ['display_total', 'asset_code'],
            '/v1/payment-discounts/update' => ['asset_code', 'chain_code', 'discount_rate'],
            default => [],
        };
        foreach ($required as $field) {
            if (!array_key_exists($field, $parameters) || $parameters[$field] === null || $parameters[$field] === '') {
                throw new ConfigurationException('A required API parameter is missing.');
            }
        }
        $identifiers = match ($path) {
            '/v1/payments/lookup', '/v1/payments/mark-paid', '/v1/payments/refund' => ['payment_reference', 'merchant_order'],
            '/v1/payments/callback/retry', '/v2/payments/callback/retry' => ['payment_reference', 'merchant_order', 'transaction_hash'],
            '/v1/withdrawals/lookup' => ['withdrawal_reference', 'merchant_order'],
            '/v1/deposit-addresses/block', '/v1/deposit-addresses/refund-blocked', '/v1/deposit-addresses/payments/search' => ['deposit_address_reference', 'merchant_order'],
            default => [],
        };
        if ($identifiers !== []) {
            $found = false;
            foreach ($identifiers as $field) {
                if (isset($parameters[$field]) && is_string($parameters[$field]) && $parameters[$field] !== '') {
                    $found = true;
                }
            }
            if (!$found) {
                throw new ConfigurationException('An API object identifier is required.');
            }
        }
        if (isset($parameters['destination']) && (!is_string($parameters['destination'])
            || !preg_match('/\A[\pL\pM\pN_-]{10,128}\z/u', $parameters['destination']))) {
            throw new ConfigurationException('Destination must contain 10 to 128 letters, digits, underscores or dashes.');
        }
        if (isset($parameters['merchant_order'])) {
            $max = in_array($path, ['/v1/payments/refund', '/v1/deposit-addresses/block', '/v1/deposit-addresses/refund-blocked', '/v1/deposit-addresses/payments/search'], true) ? 100 : 128;
            if (!is_string($parameters['merchant_order'])
                || !preg_match('/\A[\pL\pM\pN_-]{1,' . $max . '}\z/u', $parameters['merchant_order'])) {
                throw new ConfigurationException('Order identifier contains unsupported characters or length.');
            }
        }
        foreach (['simulated_success', 'simulated_failure', 'partial_allowed', 'refresh_existing', 'force_refund', 'fee_deducted'] as $field) {
            if (!array_key_exists($field, $parameters)) {
                continue;
            }
            if ($field === 'fee_deducted' && $path === '/v1/payments') {
                continue; // This operation uses an integer percentage rather than a boolean.
            }
            $value = $parameters[$field];
            if ($value === null && in_array($field, ['simulated_success', 'simulated_failure', 'partial_allowed', 'refresh_existing'], true)) {
                continue;
            }
            if (!in_array($value, [true, false, 0, 1, '0', '1'], true)) {
                throw new ConfigurationException('Boolean parameters must use true, false, 0, 1, "0" or "1".');
            }
        }
        if (isset($parameters['display_total']) && !is_string($parameters['display_total'])) {
            throw new ConfigurationException('Monetary amounts must be decimal strings.');
        }
    }
}
