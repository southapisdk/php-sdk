<?php

declare(strict_types=1);

namespace SouthApi\Responses;

use SouthApi\Exceptions\ProtocolException;

final class Page extends Result
{
    /** @var list<array<array-key, mixed>> */
    private readonly array $entries;
    public readonly int $itemCount;
    public readonly bool $hasMore;
    /** Original server has_more flag: indicates multiple pages, not necessarily a next page. */
    public readonly bool $hasPages;
    public readonly ?string $nextCursor;
    public readonly ?string $previousCursor;
    public readonly int $pageSize;

    /** @param array<array-key, mixed> $content */
    public function __construct(#[\SensitiveParameter] array $content)
    {
        parent::__construct($content);
        $entries = $content['entries'] ?? null;
        $pagination = $content['pagination'] ?? null;
        if (!is_array($entries) || !array_is_list($entries) || !is_array($pagination)
            || !is_int($pagination['item_count'] ?? null) || $pagination['item_count'] < 0
            || !is_bool($pagination['has_more'] ?? null)
            || !is_int($pagination['page_size'] ?? null) || $pagination['page_size'] < 1
            || !array_key_exists('next_cursor', $pagination) || !array_key_exists('previous_cursor', $pagination)
            || !($pagination['next_cursor'] === null || is_string($pagination['next_cursor']))
            || !($pagination['previous_cursor'] === null || is_string($pagination['previous_cursor']))
        ) {
            throw new ProtocolException('Invalid pagination response.');
        }
        $validated = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                throw new ProtocolException('Invalid pagination entry.');
            }
            $validated[] = $entry;
        }
        $this->entries = $validated;
        $this->itemCount = $pagination['item_count'];
        $this->hasPages = $pagination['has_more'];
        $this->hasMore = $pagination['next_cursor'] !== null && $pagination['next_cursor'] !== '';
        $this->nextCursor = $pagination['next_cursor'];
        $this->previousCursor = $pagination['previous_cursor'];
        $this->pageSize = $pagination['page_size'];
    }

    /** @return list<array<array-key, mixed>> */
    public function entries(): array
    {
        return $this->entries;
    }
}
