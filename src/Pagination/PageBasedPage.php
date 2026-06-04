<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Pagination;

use haddowg\JsonApi\Schema\Link\Link;

/**
 * A page of page-number + page-size pagination (`page[number]` / `page[size]`).
 *
 * Emits the full `first`/`prev`/`next`/`last` set computed from
 * `ceil(totalItems / size)`.
 *
 * @template T
 *
 * @extends AbstractPage<T>
 */
final readonly class PageBasedPage extends AbstractPage
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
            'perPage' => $this->size,
            'from' => ($this->page - 1) * $this->size + 1,
            'to' => \min($this->page * $this->size, $this->totalItems),
            'total' => $this->totalItems,
            'lastPage' => $this->lastPage(),
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
        return $this->size > 0 ? (int) \ceil($this->totalItems / $this->size) : 0;
    }
}
