<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Pagination;

use haddowg\JsonApi\Schema\Link\Link;

/**
 * A page of fixed-size page-number pagination (`page[number]` only; the page
 * size is server-determined and never echoed in the links).
 *
 * Emits the full `first`/`prev`/`next`/`last` set computed from
 * `ceil(totalItems / size)`, where `size` is the server's fixed page size used
 * only for the last-page calculation.
 *
 * @template T
 *
 * @extends AbstractPage<T>
 */
final readonly class FixedPagePage extends AbstractPage
{
    /**
     * @param iterable<T> $items
     */
    public function __construct(
        iterable $items,
        public int $totalItems,
        public int $page,
        public int $size,
    ) {
        parent::__construct($items);
    }

    public function linkSet(string $uri, string $queryString): array
    {
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
        return [
            'currentPage' => $this->page,
            'total' => $this->totalItems,
            'lastPage' => $this->lastPage(),
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
        return $this->size > 0 ? (int) \ceil($this->totalItems / $this->size) : 0;
    }
}
