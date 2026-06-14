<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Pagination;

use haddowg\JsonApi\Schema\Link\Link;

/**
 * A page of page-number + page-size pagination (`page[number]` / `page[size]`).
 *
 * With a known `$totalItems`, emits the full `first`/`prev`/`next`/`last` set
 * computed from `ceil(totalItems / size)` plus `meta.page.total`. In **count-free
 * mode** (`$totalItems === null`) the total is unknown: `last` and
 * `meta.page.{total,lastPage,to}` are omitted, and "there is a next page" is
 * signalled by `$hasMore` (the data layer fetching one item past the window)
 * rather than derived from a count — the page/offset analogue of the count-free
 * shape {@see CursorBasedPage} already models.
 *
 * @template T
 *
 * @extends AbstractPage<T>
 */
final readonly class PageBasedPage extends AbstractPage
{
    /**
     * @param iterable<T> $items
     * @param int|null    $totalItems the total of the whole collection, or `null` in count-free mode
     * @param bool        $hasMore    in count-free mode, whether a further page follows (ignored when `$totalItems` is known)
     */
    public function __construct(
        iterable $items,
        public ?int $totalItems,
        public int $page,
        public int $size,
        public bool $hasMore = false,
    ) {
        parent::__construct($items);
    }

    public function linkSet(string $uri, string $queryString): array
    {
        if ($this->totalItems === null) {
            return $this->countFreeLinkSet($uri, $queryString);
        }

        if ($this->totalItems <= 0 || $this->size <= 0) {
            return ['self' => null, 'first' => null, 'prev' => null, 'next' => null, 'last' => null];
        }

        $lastPage = $this->lastPage();

        return [
            'self' => $this->page >= 1 && $this->page <= $lastPage ? $this->link($uri, $queryString, $this->page) : null,
            'first' => $this->link($uri, $queryString, 1),
            'prev' => $this->page > 1 ? $this->link($uri, $queryString, $this->page - 1) : null,
            'next' => $this->page >= 1 && $this->page < $lastPage ? $this->link($uri, $queryString, $this->page + 1) : null,
            'last' => $this->link($uri, $queryString, $lastPage),
        ];
    }

    public function pageMeta(): array
    {
        if ($this->totalItems === null) {
            return [
                'currentPage' => $this->page,
                'perPage' => $this->size,
                'from' => ($this->page - 1) * $this->size + 1,
            ];
        }

        return [
            'currentPage' => $this->page,
            'perPage' => $this->size,
            'from' => ($this->page - 1) * $this->size + 1,
            'to' => \min($this->page * $this->size, $this->totalItems),
            'total' => $this->totalItems,
            'lastPage' => $this->lastPage(),
        ];
    }

    /**
     * The count-free link set: `self`/`first` always, `prev` when past the first
     * page, `next` from `$hasMore`, and `last` omitted (no total to locate it).
     *
     * @return array<string, Link|null>
     */
    private function countFreeLinkSet(string $uri, string $queryString): array
    {
        if ($this->size <= 0) {
            return ['self' => null, 'first' => null, 'prev' => null, 'next' => null, 'last' => null];
        }

        return [
            'self' => $this->page >= 1 ? $this->link($uri, $queryString, $this->page) : null,
            'first' => $this->link($uri, $queryString, 1),
            'prev' => $this->page > 1 ? $this->link($uri, $queryString, $this->page - 1) : null,
            'next' => $this->hasMore ? $this->link($uri, $queryString, $this->page + 1) : null,
            'last' => null,
        ];
    }

    private function link(string $uri, string $queryString, int $page): Link
    {
        return $this->paginatedLink($uri, $queryString, ['page' => ['number' => $page, 'size' => $this->size]]);
    }

    private function lastPage(): int
    {
        // Guard the divisor like linkSet() does: a zero size (page[size]=0 is
        // client-controlled) yields a degenerate empty page, never a crash.
        return $this->size > 0 ? (int) \ceil(($this->totalItems ?? 0) / $this->size) : 0;
    }
}
