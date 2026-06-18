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
        public bool $wantsCount = false,
    ) {}

    public static function make(int $size = 15): self
    {
        return new self($size);
    }

    public function withSize(int $size): self
    {
        return new self($size, $this->pageKey, $this->defaultPage, $this->wantsCount);
    }

    public function withPageKey(string $pageKey): self
    {
        return new self($this->size, $pageKey, $this->defaultPage, $this->wantsCount);
    }

    public function withDefaultPage(int $defaultPage): self
    {
        return new self($this->size, $this->pageKey, $defaultPage, $this->wantsCount);
    }

    /**
     * Opts this paginator into counting: it runs the `COUNT` on **every** paged
     * request, so `meta.page.total` and the `last` link are always present. The
     * author-always counterpart of the client's `?withCount=_self_`; no profile or
     * param needed. Count-free remains the default (omit this).
     */
    public function withCount(): self
    {
        return new self($this->size, $this->pageKey, $this->defaultPage, true);
    }

    public function wantsCount(): bool
    {
        return $this->wantsCount;
    }

    public function window(JsonApiRequestInterface $request): OffsetWindow
    {
        $page = $this->resolvePage($request);

        return new OffsetWindow(($page - 1) * \max(0, $this->size), $this->size);
    }

    /**
     * @param iterable<mixed> $items
     *
     * @return FixedPagePage<mixed>
     */
    public function paginate(JsonApiRequestInterface $request, iterable $items, int $totalItems): FixedPagePage
    {
        return new FixedPagePage($items, $totalItems, $this->resolvePage($request), $this->size);
    }

    /**
     * @param iterable<mixed> $items
     *
     * @return FixedPagePage<mixed>
     */
    public function paginateWithoutCount(JsonApiRequestInterface $request, iterable $items, bool $hasMore): FixedPagePage
    {
        return new FixedPagePage($items, null, $this->resolvePage($request), $this->size, $hasMore);
    }

    /**
     * The normalised page number (clamped to `>= 1`), shared by {@see window()}
     * and {@see paginate()} so the fetched items and the page meta/links always
     * agree, even for garbage input.
     */
    private function resolvePage(JsonApiRequestInterface $request): int
    {
        return \max(1, QueryParam::int($request->getPagination(), $this->pageKey, $this->defaultPage));
    }
}
