<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Pagination;

use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * Page-number + page-size strategy (`page[number]` / `page[size]`).
 *
 * Fluent and immutable: {@see make()} then `with…()` to override the query-param
 * keys and the defaults used when a parameter is absent (or non-numeric, which
 * falls back to the default, matching the request-side parsing rule).
 *
 * The client-controlled `page[size]` is capped at {@see $maxPerPage} (default
 * {@see DEFAULT_MAX_PER_PAGE}) so an over-large request is silently clamped to the
 * cap rather than honoured — a `page[size]=1000000` returns the cap's worth of
 * items with `200`, in keeping with the clamp-don't-`400` pagination stance. Pass
 * `0` to {@see withMaxPerPage()} to disable the cap (unlimited).
 *
 * Its {@see kind()} is `page`; rename it with {@see withKind()} when composing two
 * page strategies in one {@see MultiPaginator} menu.
 */
final readonly class PagePaginator implements PaginatorInterface
{
    /**
     * The default page-size cap, applied unless overridden with
     * {@see withMaxPerPage()}. Protects every store against an over-large
     * `page[size]` without any configuration.
     */
    public const int DEFAULT_MAX_PER_PAGE = 100;

    public function __construct(
        public string $pageKey = 'number',
        public string $perPageKey = 'size',
        public int $defaultPage = 1,
        public int $defaultPerPage = 15,
        public int $maxPerPage = self::DEFAULT_MAX_PER_PAGE,
        public bool $wantsCount = false,
        public string $kind = 'page',
    ) {}

    public static function make(): self
    {
        return new self();
    }

    public function withPageKey(string $pageKey): self
    {
        return new self($pageKey, $this->perPageKey, $this->defaultPage, $this->defaultPerPage, $this->maxPerPage, $this->wantsCount, $this->kind);
    }

    public function withPerPageKey(string $perPageKey): self
    {
        return new self($this->pageKey, $perPageKey, $this->defaultPage, $this->defaultPerPage, $this->maxPerPage, $this->wantsCount, $this->kind);
    }

    public function withDefaultPage(int $defaultPage): self
    {
        return new self($this->pageKey, $this->perPageKey, $defaultPage, $this->defaultPerPage, $this->maxPerPage, $this->wantsCount, $this->kind);
    }

    public function withDefaultPerPage(int $defaultPerPage): self
    {
        return new self($this->pageKey, $this->perPageKey, $this->defaultPage, $defaultPerPage, $this->maxPerPage, $this->wantsCount, $this->kind);
    }

    /**
     * Caps the resolved page size at `$max` items. The cap clamps an over-large
     * `page[size]` down to `$max` (the requested size is honoured up to it), so it
     * never *raises* a smaller request. Pass `0` to disable the cap (unlimited).
     */
    public function withMaxPerPage(int $max): self
    {
        return new self($this->pageKey, $this->perPageKey, $this->defaultPage, $this->defaultPerPage, \max(0, $max), $this->wantsCount, $this->kind);
    }

    /**
     * Opts this paginator into counting: it runs the `COUNT` on **every** paged
     * request, so `meta.page.total` and the `last` link are always present. The
     * author-always counterpart of the client's `?withCount=_self_`; no profile or
     * param needed. Count-free remains the default (omit this).
     */
    public function withCount(): self
    {
        return new self($this->pageKey, $this->perPageKey, $this->defaultPage, $this->defaultPerPage, $this->maxPerPage, true, $this->kind);
    }

    /**
     * Renames the strategy's {@see kind()} discriminator — the `page[kind]` value
     * (and OpenAPI `oneOf` branch `const`) that selects it in a {@see MultiPaginator}
     * menu. Use it to compose two page strategies without a collision.
     */
    public function withKind(string $kind): self
    {
        return new self($this->pageKey, $this->perPageKey, $this->defaultPage, $this->defaultPerPage, $this->maxPerPage, $this->wantsCount, $kind);
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
            ->withProperty($this->pageKey, Schema::ofType('integer')->withMinimum(1)->withDescription('The page number to retrieve.'))
            ->withProperty($this->perPageKey, Schema::ofType('integer')->withMinimum(1)->withDescription('The number of resources per page.'));
    }

    public function resolve(JsonApiRequestInterface $request): PaginatorInterface
    {
        return $this;
    }

    public function window(JsonApiRequestInterface $request): OffsetWindow
    {
        [$page, $size] = $this->resolvePage($request);

        return new OffsetWindow(($page - 1) * $size, $size);
    }

    /**
     * @param iterable<mixed> $items
     *
     * @return PageBasedPage<mixed>
     */
    public function paginate(JsonApiRequestInterface $request, iterable $items, int $totalItems): PageBasedPage
    {
        [$page, $size] = $this->resolvePage($request);

        return new PageBasedPage($items, $totalItems, $page, $size);
    }

    /**
     * @param iterable<mixed> $items
     *
     * @return PageBasedPage<mixed>
     */
    public function paginateWithoutCount(JsonApiRequestInterface $request, iterable $items, bool $hasMore): PageBasedPage
    {
        [$page, $size] = $this->resolvePage($request);

        return new PageBasedPage($items, null, $page, $size, $hasMore);
    }

    /**
     * The normalised `[page, size]` for the request — page clamped to `>= 1`,
     * size to `>= 0` and then to at most {@see $maxPerPage} (when the cap is
     * enabled). One derivation shared by {@see window()} and {@see paginate()}, so
     * the items a data layer fetches and the page meta/links that describe them
     * always agree, even for garbage input.
     *
     * @return array{int, int}
     */
    private function resolvePage(JsonApiRequestInterface $request): array
    {
        $pagination = $request->getPagination();

        $size = \max(0, QueryParam::int($pagination, $this->perPageKey, $this->defaultPerPage));

        return [
            \max(1, QueryParam::int($pagination, $this->pageKey, $this->defaultPage)),
            $this->maxPerPage > 0 ? \min($size, $this->maxPerPage) : $size,
        ];
    }
}
