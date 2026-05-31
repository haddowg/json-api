<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Pagination;

use haddowg\JsonApi\Schema\Link\Link;

/**
 * A page of offset + limit pagination (`page[offset]` / `page[limit]`).
 *
 * Emits the full `first`/`prev`/`next`/`last` set.
 *
 * @template T
 *
 * @extends AbstractPage<T>
 */
final readonly class OffsetBasedPage extends AbstractPage
{
    /**
     * @param iterable<T> $items
     */
    public function __construct(
        iterable $items,
        public int $totalItems,
        public int $offset,
        public int $limit,
    ) {
        parent::__construct($items);
    }

    public function linkSet(string $uri, string $queryString): array
    {
        if ($this->totalItems <= 0 || $this->limit <= 0) {
            return ['self' => null, 'first' => null, 'prev' => null, 'next' => null, 'last' => null];
        }

        return [
            'self' => $this->offset >= 0 && $this->offset < $this->totalItems
                ? $this->link($uri, $queryString, $this->offset)
                : null,
            'first' => $this->link($uri, $queryString, 0),
            'prev' => $this->offset > 0 && $this->offset + $this->limit <= $this->totalItems
                ? $this->link($uri, $queryString, \max($this->offset - $this->limit, 0))
                : null,
            'next' => $this->offset >= 0 && $this->offset + $this->limit < $this->totalItems
                ? $this->link($uri, $queryString, $this->offset + $this->limit)
                : null,
            'last' => $this->link($uri, $queryString, \max($this->totalItems - $this->limit, 0)),
        ];
    }

    public function pageMeta(): array
    {
        return [
            'offset' => $this->offset,
            'limit' => $this->limit,
            'from' => $this->offset + 1,
            'to' => \min($this->offset + $this->limit, $this->totalItems),
            'total' => $this->totalItems,
        ];
    }

    private function link(string $uri, string $queryString, int $offset): Link
    {
        return $this->paginatedLink($uri, $queryString, ['page' => ['offset' => $offset, 'limit' => $this->limit]]);
    }
}
