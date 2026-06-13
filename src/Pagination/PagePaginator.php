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
 *
 * The client-controlled `page[size]` is capped at {@see $maxPerPage} (default
 * {@see DEFAULT_MAX_PER_PAGE}) so an over-large request is silently clamped to the
 * cap rather than honoured — a `page[size]=1000000` returns the cap's worth of
 * items with `200`, in keeping with the clamp-don't-`400` pagination stance. Pass
 * `0` to {@see withMaxPerPage()} to disable the cap (unlimited).
 */
final readonly class PagePaginator implements \haddowg\JsonApi\Pagination\PaginatorInterface
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
    ) {}

    public static function make(): self
    {
        return new self();
    }

    public function withPageKey(string $pageKey): self
    {
        return new self($pageKey, $this->perPageKey, $this->defaultPage, $this->defaultPerPage, $this->maxPerPage);
    }

    public function withPerPageKey(string $perPageKey): self
    {
        return new self($this->pageKey, $perPageKey, $this->defaultPage, $this->defaultPerPage, $this->maxPerPage);
    }

    public function withDefaultPage(int $defaultPage): self
    {
        return new self($this->pageKey, $this->perPageKey, $defaultPage, $this->defaultPerPage, $this->maxPerPage);
    }

    public function withDefaultPerPage(int $defaultPerPage): self
    {
        return new self($this->pageKey, $this->perPageKey, $this->defaultPage, $defaultPerPage, $this->maxPerPage);
    }

    /**
     * Caps the resolved page size at `$max` items. The cap clamps an over-large
     * `page[size]` down to `$max` (the requested size is honoured up to it), so it
     * never *raises* a smaller request. Pass `0` to disable the cap (unlimited).
     */
    public function withMaxPerPage(int $max): self
    {
        return new self($this->pageKey, $this->perPageKey, $this->defaultPage, $this->defaultPerPage, \max(0, $max));
    }

    public function window(JsonApiRequestInterface $request): OffsetWindow
    {
        [$page, $size] = $this->resolve($request);

        return new OffsetWindow(($page - 1) * $size, $size);
    }

    /**
     * @param iterable<mixed> $items
     *
     * @return PageBasedPage<mixed>
     */
    public function paginate(JsonApiRequestInterface $request, iterable $items, int $totalItems): PageBasedPage
    {
        [$page, $size] = $this->resolve($request);

        return new PageBasedPage($items, $totalItems, $page, $size);
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
    private function resolve(JsonApiRequestInterface $request): array
    {
        $pagination = $request->getPagination();

        $size = \max(0, QueryParam::int($pagination, $this->perPageKey, $this->defaultPerPage));

        return [
            \max(1, QueryParam::int($pagination, $this->pageKey, $this->defaultPage)),
            $this->maxPerPage > 0 ? \min($size, $this->maxPerPage) : $size,
        ];
    }
}
