<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Pagination;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * Fixed-size page-number strategy (`page[number]` only; the page size is fixed by
 * the server and not part of the query).
 *
 * The configured {@see $size} is the server's fixed page size, used to compute
 * the last page; it is never echoed in the emitted links. Fluent and immutable.
 */
final readonly class FixedPagePaginator implements \haddowg\JsonApi\Pagination\PaginatorInterface
{
    public function __construct(
        public int $size = 15,
        public string $pageKey = 'number',
        public int $defaultPage = 1,
    ) {}

    public static function make(int $size = 15): self
    {
        return new self($size);
    }

    public function withSize(int $size): self
    {
        return new self($size, $this->pageKey, $this->defaultPage);
    }

    public function withPageKey(string $pageKey): self
    {
        return new self($this->size, $pageKey, $this->defaultPage);
    }

    public function withDefaultPage(int $defaultPage): self
    {
        return new self($this->size, $this->pageKey, $defaultPage);
    }

    /**
     * @param iterable<mixed> $items
     *
     * @return FixedPagePage<mixed>
     */
    public function paginate(JsonApiRequestInterface $request, iterable $items, int $totalItems): FixedPagePage
    {
        $pagination = $request->getPagination();

        return new FixedPagePage(
            $items,
            $totalItems,
            QueryParam::int($pagination, $this->pageKey, $this->defaultPage),
            $this->size,
        );
    }
}
