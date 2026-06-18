<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Pagination;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * Offset + limit strategy (`page[offset]` / `page[limit]`).
 *
 * Fluent and immutable: {@see make()} then `with…()` to override the query-param
 * keys and the defaults used when a parameter is absent.
 *
 * The client-controlled `page[limit]` is capped at {@see $maxPerPage} (default
 * {@see PagePaginator::DEFAULT_MAX_PER_PAGE}) so an over-large request is silently
 * clamped to the cap rather than honoured — `page[limit]=1000000` returns the
 * cap's worth of items with `200`, in keeping with the clamp-don't-`400`
 * pagination stance. Pass `0` to {@see withMaxPerPage()} to disable the cap
 * (unlimited).
 */
final readonly class OffsetPaginator implements \haddowg\JsonApi\Pagination\PaginatorInterface
{
    public function __construct(
        public string $offsetKey = 'offset',
        public string $limitKey = 'limit',
        public int $defaultOffset = 0,
        public int $defaultLimit = 15,
        public int $maxPerPage = PagePaginator::DEFAULT_MAX_PER_PAGE,
        public bool $wantsCount = false,
    ) {}

    public static function make(): self
    {
        return new self();
    }

    public function withOffsetKey(string $offsetKey): self
    {
        return new self($offsetKey, $this->limitKey, $this->defaultOffset, $this->defaultLimit, $this->maxPerPage, $this->wantsCount);
    }

    public function withLimitKey(string $limitKey): self
    {
        return new self($this->offsetKey, $limitKey, $this->defaultOffset, $this->defaultLimit, $this->maxPerPage, $this->wantsCount);
    }

    public function withDefaultOffset(int $defaultOffset): self
    {
        return new self($this->offsetKey, $this->limitKey, $defaultOffset, $this->defaultLimit, $this->maxPerPage, $this->wantsCount);
    }

    public function withDefaultLimit(int $defaultLimit): self
    {
        return new self($this->offsetKey, $this->limitKey, $this->defaultOffset, $defaultLimit, $this->maxPerPage, $this->wantsCount);
    }

    /**
     * Caps the resolved limit at `$max` items. The cap clamps an over-large
     * `page[limit]` down to `$max` (the requested limit is honoured up to it), so
     * it never *raises* a smaller request. Pass `0` to disable the cap (unlimited).
     */
    public function withMaxPerPage(int $max): self
    {
        return new self($this->offsetKey, $this->limitKey, $this->defaultOffset, $this->defaultLimit, \max(0, $max), $this->wantsCount);
    }

    /**
     * Opts this paginator into counting: it runs the `COUNT` on **every** paged
     * request, so `meta.page.total` and the `last` link are always present. The
     * author-always counterpart of the client's `?withCount=_self_`; no profile or
     * param needed. Count-free remains the default (omit this).
     */
    public function withCount(): self
    {
        return new self($this->offsetKey, $this->limitKey, $this->defaultOffset, $this->defaultLimit, $this->maxPerPage, true);
    }

    public function wantsCount(): bool
    {
        return $this->wantsCount;
    }

    public function window(JsonApiRequestInterface $request): OffsetWindow
    {
        $pagination = $request->getPagination();

        $limit = QueryParam::int($pagination, $this->limitKey, $this->defaultLimit);

        // OffsetWindow normalises to >= 0; the cap clamps an over-large limit down
        // to maxPerPage. paginate() reuses the same window so the fetched items and
        // the page meta/links always agree.
        return new OffsetWindow(
            QueryParam::int($pagination, $this->offsetKey, $this->defaultOffset),
            $this->maxPerPage > 0 ? \min(\max(0, $limit), $this->maxPerPage) : $limit,
        );
    }

    /**
     * @param iterable<mixed> $items
     *
     * @return OffsetBasedPage<mixed>
     */
    public function paginate(JsonApiRequestInterface $request, iterable $items, int $totalItems): OffsetBasedPage
    {
        $window = $this->window($request);

        return new OffsetBasedPage($items, $totalItems, $window->offset, $window->limit);
    }

    /**
     * @param iterable<mixed> $items
     *
     * @return OffsetBasedPage<mixed>
     */
    public function paginateWithoutCount(JsonApiRequestInterface $request, iterable $items, bool $hasMore): OffsetBasedPage
    {
        $window = $this->window($request);

        return new OffsetBasedPage($items, null, $window->offset, $window->limit, $hasMore);
    }
}
