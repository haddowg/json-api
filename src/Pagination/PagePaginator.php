<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Pagination;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * Page-number + page-size strategy (`page[number]` / `page[size]`).
 *
 * Fluent and immutable: {@see make()} then `with…()` to override the query-param
 * keys and the defaults used when a parameter is absent (or non-numeric, which
 * falls back to the default, matching the request-side parsing rule).
 */
final readonly class PagePaginator implements \haddowg\JsonApi\Pagination\PaginatorInterface
{
    public function __construct(
        public string $pageKey = 'number',
        public string $perPageKey = 'size',
        public int $defaultPage = 1,
        public int $defaultPerPage = 15,
    ) {}

    public static function make(): self
    {
        return new self();
    }

    public function withPageKey(string $pageKey): self
    {
        return new self($pageKey, $this->perPageKey, $this->defaultPage, $this->defaultPerPage);
    }

    public function withPerPageKey(string $perPageKey): self
    {
        return new self($this->pageKey, $perPageKey, $this->defaultPage, $this->defaultPerPage);
    }

    public function withDefaultPage(int $defaultPage): self
    {
        return new self($this->pageKey, $this->perPageKey, $defaultPage, $this->defaultPerPage);
    }

    public function withDefaultPerPage(int $defaultPerPage): self
    {
        return new self($this->pageKey, $this->perPageKey, $this->defaultPage, $defaultPerPage);
    }

    /**
     * @param iterable<mixed> $items
     *
     * @return PageBasedPage<mixed>
     */
    public function paginate(JsonApiRequestInterface $request, iterable $items, int $totalItems): PageBasedPage
    {
        $pagination = $request->getPagination();

        return new PageBasedPage(
            $items,
            $totalItems,
            QueryParam::int($pagination, $this->pageKey, $this->defaultPage),
            QueryParam::int($pagination, $this->perPageKey, $this->defaultPerPage),
        );
    }
}
