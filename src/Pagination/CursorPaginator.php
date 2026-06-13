<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Pagination;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Profile\ProfileInterface;

/**
 * Cursor strategy (`page[size]` / `page[after]` / `page[before]`), aligned to the
 * published cursor-pagination profile.
 *
 * Distinct from the count-based paginators: a cursor page has no total count, and
 * its `prev`/`next` boundaries are the cursors of the returned items, which only
 * the caller can extract from the domain data. {@see paginate()} therefore takes
 * the boundary cursors and the has-more/has-previous flags directly rather than a
 * total. It does not implement {@see Paginator} (whose contract is total-based).
 * The produced {@see CursorBasedPage} carries the {@see CursorPaginationProfile}
 * so the response advertises it.
 *
 * The client-controlled `page[size]` is capped at {@see $maxPerPage} (default
 * {@see PagePaginator::DEFAULT_MAX_PER_PAGE}) so an over-large request is silently
 * clamped to the cap rather than honoured. Pass `0` to {@see withMaxPerPage()} to
 * disable the cap (unlimited).
 *
 * @see https://jsonapi.org/profiles/ethanresnick/cursor-pagination/
 */
final readonly class CursorPaginator
{
    public function __construct(
        public int $defaultSize = 15,
        public string $sizeKey = 'size',
        public ProfileInterface $profile = new CursorPaginationProfile(),
        public int $maxPerPage = PagePaginator::DEFAULT_MAX_PER_PAGE,
    ) {}

    public static function make(): self
    {
        return new self();
    }

    public function withDefaultSize(int $defaultSize): self
    {
        return new self($defaultSize, $this->sizeKey, $this->profile, $this->maxPerPage);
    }

    public function withSizeKey(string $sizeKey): self
    {
        return new self($this->defaultSize, $sizeKey, $this->profile, $this->maxPerPage);
    }

    /**
     * Caps the resolved page size at `$max` items. The cap clamps an over-large
     * `page[size]` down to `$max` (the requested size is honoured up to it), so it
     * never *raises* a smaller request. Pass `0` to disable the cap (unlimited).
     */
    public function withMaxPerPage(int $max): self
    {
        return new self($this->defaultSize, $this->sizeKey, $this->profile, \max(0, $max));
    }

    /**
     * @param iterable<mixed> $items
     * @param int|string      $cursorBefore the cursor of the first returned item (for `prev`)
     * @param int|string      $cursorAfter  the cursor of the last returned item (for `next`)
     *
     * @return CursorBasedPage<mixed>
     */
    public function paginate(
        JsonApiRequestInterface $request,
        iterable $items,
        int|string $cursorBefore,
        int|string $cursorAfter,
        bool $hasNext,
        bool $hasPrevious,
    ): CursorBasedPage {
        $size = QueryParam::int($request->getPagination(), $this->sizeKey, $this->defaultSize);

        return new CursorBasedPage(
            $items,
            $this->maxPerPage > 0 ? \min($size, $this->maxPerPage) : $size,
            $cursorBefore,
            $cursorAfter,
            $hasNext,
            $hasPrevious,
            $this->profile,
        );
    }
}
