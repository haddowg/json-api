<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Pagination;

use haddowg\JsonApi\Schema\Link\Link;

/**
 * A page of offset + limit pagination (`page[offset]` / `page[limit]`).
 *
 * With a known `$totalItems`, emits the full `first`/`prev`/`next`/`last` set plus
 * `meta.page.total`. In **count-free mode** (`$totalItems === null`) the total is
 * unknown: `last` and `meta.page.{total,to}` are omitted, and "there is a next
 * page" is signalled by `$hasMore` (the data layer fetching one item past the
 * window) rather than derived from a count.
 *
 * @template T
 *
 * @extends AbstractPage<T>
 */
final readonly class OffsetBasedPage extends AbstractPage
{
    /**
     * @param iterable<T> $items
     * @param int|null    $totalItems the total of the whole collection, or `null` in count-free mode
     * @param bool        $hasMore    in count-free mode, whether a further page follows (ignored when `$totalItems` is known)
     */
    public function __construct(
        iterable $items,
        public ?int $totalItems,
        public int $offset,
        public int $limit,
        public bool $hasMore = false,
    ) {
        parent::__construct($items);
    }

    public function linkSet(string $uri, string $queryString): array
    {
        if ($this->totalItems === null) {
            return $this->countFreeLinkSet($uri, $queryString);
        }

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
        if ($this->totalItems === null) {
            return [
                'offset' => $this->offset,
                'limit' => $this->limit,
                'from' => $this->offset + 1,
            ];
        }

        return [
            'offset' => $this->offset,
            'limit' => $this->limit,
            'from' => $this->offset + 1,
            'to' => \min($this->offset + $this->limit, $this->totalItems),
            'total' => $this->totalItems,
        ];
    }

    /**
     * The count-free link set: `self`/`first` always, `prev` when past the first
     * window, `next` from `$hasMore`, and `last` omitted (no total to locate it).
     *
     * @return array<string, Link|null>
     */
    private function countFreeLinkSet(string $uri, string $queryString): array
    {
        if ($this->limit <= 0) {
            return ['self' => null, 'first' => null, 'prev' => null, 'next' => null, 'last' => null];
        }

        return [
            'self' => $this->offset >= 0 ? $this->link($uri, $queryString, $this->offset) : null,
            'first' => $this->link($uri, $queryString, 0),
            'prev' => $this->offset > 0 ? $this->link($uri, $queryString, \max($this->offset - $this->limit, 0)) : null,
            'next' => $this->hasMore ? $this->link($uri, $queryString, $this->offset + $this->limit) : null,
            'last' => null,
        ];
    }

    private function link(string $uri, string $queryString, int $offset): Link
    {
        return $this->paginatedLink($uri, $queryString, ['page' => ['offset' => $offset, 'limit' => $this->limit]]);
    }
}
