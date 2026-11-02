<?php

declare(strict_types=1);

namespace SouthApi\Http;

use SouthApi\Exceptions\ConfigurationException;

/** Minimal documented shape validation; business rules remain server-owned. */
final class Parameters
{
    /** @param array<string, mixed>|null $parameters
     * @param array<string, string|int> $query
     */
    public static function validate(string $method, string $path, #[\SensitiveParameter] ?array $parameters, #[\SensitiveParameter] array $query): void
    {
        if ($method === 'GET' && $parameters !== null) {
            self::invalid();
        }
        foreach ($query as $key => $value) {
            if ($key === 'page_size') {
                if (!is_int($value) || $value < 1 || $value > 100) {
                    self::invalid();
                }
            } elseif (!is_string($value)) {
                self::invalid();
            }
        }
        $allowed = ['cursor', 'page_size', 'created_from', 'created_to'];
        if (in_array($path, ['/api/v1/charges', '/api/v1/cashouts'], true)) {
            $allowed[] = 'merchant_order';
        }
        if ($path === '/api/v1/bills') {
            $allowed = array_merge($allowed, ['phase', 'recipient', 'query', 'expires_from', 'expires_to']);
        }
        if (array_diff(array_keys($query), $allowed) !== []) {
            self::invalid();
        }
        foreach (['created_from', 'created_to', 'expires_from', 'expires_to'] as $key) {
            if (isset($query[$key]) && (!is_string($query[$key]) || !preg_match('/\A\d{4}-\d{2}-\d{2}(?:T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2}))?\z/', $query[$key]))) {
                self::invalid();
            }
        }
        if ($method === 'GET') {
            return;
        }
        $required = match ($path) {
            '/api/v1/charges' => ['merchant_order', 'pricing.display_total', 'pricing.asset_code'],
            '/api/v1/cashouts' => ['merchant_order', 'amount.display_total', 'amount.asset_code', 'chain.destination', 'fee_deducted'],
            '/api/v1/cashouts/quote' => ['amount.display_total', 'amount.asset_code', 'chain.destination', 'fee_deducted'],
            '/api/v1/receiving-addresses' => ['merchant_order', 'asset.asset_code', 'asset.chain_code'],
            '/api/v1/fund-transfers' => ['direction', 'amount.display_total', 'amount.asset_code'],
            '/api/v1/charges/callbacks' => ['transaction_hash'],
            '/api/v1/bills' => ['subject', 'category', 'pricing.asset_code'],
            default => [],
        };
        if (str_ends_with($path, '/refunds')) {
            $required = ['destination', 'fee_deducted'];
        } elseif (str_ends_with($path, '/refund-blocked')) {
            $required = ['destination'];
        } elseif (str_starts_with($path, '/api/v1/charge-discounts/')) {
            $required = ['discount_rate'];
        }
        $values = $parameters ?? [];
        foreach ($required as $field) {
            $value = self::value($values, $field);
            if ($value === null || $value === '') {
                self::invalid();
            }
        }
        if ($path === '/api/v1/cashouts/quote') {
            foreach (['merchant_order', 'links', 'simulation', 'chain.memo', 'amount.source_asset'] as $excluded) {
                if (self::has($values, $excluded)) {
                    self::invalid();
                }
            }
        }
        self::walk($values, $path);
        if ($path === '/api/v1/fund-transfers' && !in_array($values['direction'] ?? null, ['to_personal', 'to_business'], true)) {
            self::invalid();
        }
    }

    /** @param array<array-key, mixed> $values */
    private static function walk(#[\SensitiveParameter] array $values, string $path, string $prefix = ''): void
    {
        foreach ($values as $key => $value) {
            $field = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (in_array($field, ['pricing', 'settlement', 'checkout', 'links', 'customer', 'simulation', 'amount', 'chain', 'asset', 'refund', 'contacts', 'recipient'], true) && $value !== null && !is_array($value)) {
                self::invalid();
            }
            if ($value === null) {
                continue;
            }
            if (in_array($key, ['display_total', 'pre_tax_total', 'tax_total', 'unit_price', 'payment_tolerance_rate'], true)) {
                if (!is_string($value) || ($path === '/api/v1/charges' && $field === 'pricing.display_total' && strlen($value) > 40) || !preg_match('/\A\d+(?:\.\d+)?\z/', $value)) {
                    self::invalid();
                }
            }
            if (in_array($key, ['simulated_success', 'simulated_failure', 'partial_allowed', 'refresh_existing', 'force_refund', 'recipient_details_required', 'use_merchant_contact'], true) || ($key === 'fee_deducted' && $path !== '/api/v1/charges')) {
                if (!is_bool($value)) {
                    self::invalid();
                }
            }
            if ($path === '/api/v1/charges' && $field === 'pricing.fee_deducted' && (!is_int($value) || $value < 0 || $value > 100)) {
                self::invalid();
            }
            if ($key === 'merchant_order') {
                $max = $path === '/api/v1/cashouts' ? 100 : 128;
                if (!is_string($value) || !preg_match('/\A[\pL\pM\pN_-]{1,' . $max . '}\z/u', $value)) {
                    self::invalid();
                }
            }
            if ($key === 'destination' && (!is_string($value) || !preg_match('/\A[\pL\pM\pN_-]{10,128}\z/u', $value))) {
                self::invalid();
            }
            if ($key === 'discount_rate') {
                $max = $path === '/api/v1/charges' ? 90 : 100;
                if (!is_int($value) || $value < -99 || $value > $max) {
                    self::invalid();
                }
            }
            if (is_array($value)) {
                self::walk($value, $path, $field);
            }
        }
    }

    /** @param array<string, mixed> $values */
    private static function value(array $values, string $path): mixed
    {
        $value = $values;
        foreach (explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }
        return $value;
    }

    /** @param array<string, mixed> $values */
    private static function has(array $values, string $path): bool
    {
        $value = $values;
        foreach (explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return false;
            }
            $value = $value[$key];
        }
        return true;
    }

    private static function invalid(): never
    {
        throw new ConfigurationException('Request parameters do not match the API contract.');
    }
}
