<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Pagination;

use haddowg\JsonApi\Schema\Link\Link;

/**
 * A page of fixed-size page-number pagination (`page[number]` only; the page
 * size is server-determined and never echoed in the links).
 *
 * With a known `$totalItems`, emits the full `first`/`prev`/`next`/`last` set
 * computed from `ceil(totalItems / size)` plus `meta.page.{total,lastPage}`, where
 * `size` is the server's fixed page size used only for the last-page calculation.
 * In **count-free mode** (`$totalItems === null`) the total is unknown: `last` and
 * `meta.page.{total,lastPage}` are omitted, and "there is a next page" is signalled
 * by `$hasMore` (the data layer fetching one item past the window) rather than
 * derived from a count.
 *
 * @template T
 *
 * @extends AbstractPage<T>
 */
final readonly class FixedPagePage extends AbstractPage
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
            ];
        }

        return [
            'currentPage' => $this->page,
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
        return $this->paginatedLink($uri, $queryString, ['page' => ['number' => $page]]);
    }

    private function lastPage(): int
    {
        // Guard the divisor like linkSet() does: a zero configured size yields
        // a degenerate empty page, never a crash.
        return $this->size > 0 ? (int) \ceil(($this->totalItems ?? 0) / $this->size) : 0;
    }
}
