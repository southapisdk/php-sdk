<?php

declare(strict_types=1);

namespace SouthApi\Responses;

use SouthApi\Exceptions\ProtocolException;

/** Preserves the server's content[0].balances envelope and provides wallet accessors. */
final class BalanceResult extends Result
{
    /** @var array<string, list<array<array-key, mixed>>> */
    private readonly array $wallets;

    /** @param array<array-key, mixed> $content */
    public function __construct(#[\SensitiveParameter] array $content)
    {
        parent::__construct($content);
        if (!array_is_list($content) || count($content) !== 1 || !is_array($content[0])) {
            throw new ProtocolException('Invalid balances response.');
        }
        $balances = $content[0]['balances'] ?? [];
        if (!is_array($balances)) {
            throw new ProtocolException('Invalid balances response.');
        }
        $wallets = [];
        foreach (['business', 'personal'] as $kind) {
            $entries = $balances[$kind] ?? [];
            if (!is_array($entries) || !array_is_list($entries)) {
                throw new ProtocolException('Invalid balances response.');
            }
            $validated = [];
            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    throw new ProtocolException('Invalid balance wallet.');
                }
                $validated[] = $entry;
            }
            $wallets[$kind] = $validated;
        }
        $this->wallets = $wallets;
    }

    /** @return list<array<array-key, mixed>> */
    public function business(): array
    {
        return $this->wallets['business'];
    }

    /** @return list<array<array-key, mixed>> */
    public function personal(): array
    {
        return $this->wallets['personal'];
    }
}
