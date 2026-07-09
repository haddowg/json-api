<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Pagination;

use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * Fixed-size page-number strategy (`page[number]` only; the page size is fixed by
 * the server and not part of the query).
 *
 * The configured {@see $size} is the server's fixed page size, used to compute
 * the last page; it is never echoed in the emitted links. Fluent and immutable.
 *
 * Its {@see kind()} is `fixed`; rename it with {@see withKind()} when composing
 * two fixed strategies in one {@see MultiPaginator} menu.
 */
final readonly class FixedPagePaginator implements PaginatorInterface
{
    public function __construct(
        public int $size = 15,
        public string $pageKey = 'number',
        public int $defaultPage = 1,
        public bool $wantsCount = false,
        public string $kind = 'fixed',
    ) {}

    public static function make(int $size = 15): self
    {
        return new self($size);
    }

    public function withSize(int $size): self
    {
        return new self($size, $this->pageKey, $this->defaultPage, $this->wantsCount, $this->kind);
    }

    public function withPageKey(string $pageKey): self
    {
        return new self($this->size, $pageKey, $this->defaultPage, $this->wantsCount, $this->kind);
    }

    public function withDefaultPage(int $defaultPage): self
    {
        return new self($this->size, $this->pageKey, $defaultPage, $this->wantsCount, $this->kind);
    }

    /**
     * Opts this paginator into counting: it runs the `COUNT` on **every** paged
     * request, so `meta.page.total` and the `last` link are always present. The
     * author-always counterpart of the client's `?withCount=_self_`; no profile or
     * param needed. Count-free remains the default (omit this).
     */
    public function withCount(): self
    {
        return new self($this->size, $this->pageKey, $this->defaultPage, true, $this->kind);
    }

    /**
     * Renames the strategy's {@see kind()} discriminator — the `page[kind]` value
     * (and OpenAPI `oneOf` branch `const`) that selects it in a {@see MultiPaginator}
     * menu.
     */
    public function withKind(string $kind): self
    {
        return new self($this->size, $this->pageKey, $this->defaultPage, $this->wantsCount, $kind);
    }

    public function wantsCount(): bool
    {
        return $this->wantsCount;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function describePageSchema(): Schema
    {
        return Schema::ofType('object')
            ->withProperty($this->pageKey, Schema::ofType('integer')->withMinimum(1)->withDescription('The page number to retrieve.'));
    }

    public function resolve(JsonApiRequestInterface $request): PaginatorInterface
    {
        return $this;
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
