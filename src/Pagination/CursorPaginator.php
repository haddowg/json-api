<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Pagination;

use haddowg\JsonApi\Exception\MalformedCursor;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Profile\ProfileInterface;

/**
 * Cursor (keyset) strategy (`page[size]` / `page[after]` / `page[before]`),
 * aligned to the published cursor-pagination profile.
 *
 * Distinct from the count-based paginators: a cursor page has no total count, and
 * its `prev`/`next` boundaries are the cursors of the returned items, which only
 * the executing provider can mint (it owns the row → boundary-value reader). It
 * still {@see PaginatorInterface::window() implements PaginatorInterface}
 * (Option A): {@see window()} returns a {@see CursorWindow} the provider executes
 * as a keyset fetch, and {@see paginate()} / {@see paginateWithoutCount()} are
 * count-free conveniences — the **cursor path** is {@see fromBoundaries()}, which
 * takes the minted boundary cursors and the has-more/has-previous flags directly
 * rather than a total. `paginate(…, $totalItems)` is *not* the cursor path: a
 * cursor strategy never derives a total, so the count argument is ignored.
 *
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
final readonly class CursorPaginator implements PaginatorInterface
{
    /**
     * The `page[…]` keys reserved for the cursor tokens; their wire form is
     * `page[after]` / `page[before]`, used for the malformed-cursor error source.
     */
    private const string AFTER_KEY = 'after';

    private const string BEFORE_KEY = 'before';

    public function __construct(
        public int $defaultSize = 15,
        public string $sizeKey = 'size',
        public ProfileInterface $profile = new CursorPaginationProfile(),
        public int $maxPerPage = PagePaginator::DEFAULT_MAX_PER_PAGE,
        public CursorCodec $codec = new CursorCodec(),
    ) {}

    public static function make(): self
    {
        return new self();
    }

    public function withDefaultSize(int $defaultSize): self
    {
        return new self($defaultSize, $this->sizeKey, $this->profile, $this->maxPerPage, $this->codec);
    }

    public function withSizeKey(string $sizeKey): self
    {
        return new self($this->defaultSize, $sizeKey, $this->profile, $this->maxPerPage, $this->codec);
    }

    /**
     * Caps the resolved page size at `$max` items. The cap clamps an over-large
     * `page[size]` down to `$max` (the requested size is honoured up to it), so it
     * never *raises* a smaller request. Pass `0` to disable the cap (unlimited).
     */
    public function withMaxPerPage(int $max): self
    {
        return new self($this->defaultSize, $this->sizeKey, $this->profile, \max(0, $max), $this->codec);
    }

    /**
     * The keyset fetch window for this request: the resolved page size plus the
     * decoded `page[after]` / `page[before]` boundaries (each `null` when absent),
     * exposed **before** any items are materialized so the provider can run the
     * keyset fetch. The window deliberately carries no resolved sort — the
     * provider resolves the active sort off the request when executing (C2/C3).
     *
     * @throws MalformedCursor when a supplied cursor token cannot be decoded
     */
    public function window(JsonApiRequestInterface $request): CursorWindow
    {
        $pagination = $request->getPagination();

        return new CursorWindow(
            $this->resolveSize($request),
            $this->decodeBoundary($pagination, self::AFTER_KEY),
            $this->decodeBoundary($pagination, self::BEFORE_KEY),
        );
    }

    /**
     * The cursor path: build a page from the boundary cursors the provider minted
     * and the has-more/has-previous flags. This is the method the data layer calls
     * — {@see paginate()} / {@see paginateWithoutCount()} are the count-based
     * interface conveniences and delegate here with empty boundaries.
     *
     * @param iterable<mixed>      $items
     * @param int|string           $cursorBefore the cursor of the first returned item (for `prev`)
     * @param int|string           $cursorAfter  the cursor of the last returned item (for `next`)
     * @param int|string|null      $from         the id of the first row on the page (for `meta.page.from`)
     * @param int|string|null      $to           the id of the last row on the page (for `meta.page.to`)
     *
     * @return CursorBasedPage<mixed>
     */
    public function fromBoundaries(
        JsonApiRequestInterface $request,
        iterable $items,
        int|string $cursorBefore,
        int|string $cursorAfter,
        bool $hasNext,
        bool $hasPrevious,
        int|string|null $from = null,
        int|string|null $to = null,
    ): CursorBasedPage {
        return new CursorBasedPage(
            $items,
            $this->resolveSize($request),
            $cursorBefore,
            $cursorAfter,
            $hasNext,
            $hasPrevious,
            $this->profile,
            $from,
            $to,
        );
    }

    /**
     * Count-based interface conformance: a cursor strategy is count-free, so the
     * `$totalItems` argument is ignored and the page is built without boundary
     * cursors (no `prev`/`next`). This is **not** the cursor path — use
     * {@see fromBoundaries()} for a real keyset page.
     *
     * @param iterable<mixed> $items
     *
     * @return CursorBasedPage<mixed>
     */
    public function paginate(JsonApiRequestInterface $request, iterable $items, int $totalItems): CursorBasedPage
    {
        return $this->fromBoundaries($request, $items, '', '', false, false);
    }

    /**
     * Count-free interface conformance: builds a page carrying only `$hasMore`
     * (the `next` driver) with no boundary cursors. The real cursor path is
     * {@see fromBoundaries()}, which also carries the minted tokens.
     *
     * @param iterable<mixed> $items
     *
     * @return CursorBasedPage<mixed>
     */
    public function paginateWithoutCount(JsonApiRequestInterface $request, iterable $items, bool $hasMore): CursorBasedPage
    {
        return $this->fromBoundaries($request, $items, '', '', $hasMore, false);
    }

    /**
     * The normalised page size for the request — floored to `>= 1` (a keyset
     * fetch always returns at least one row, so `page[size]=0`/negative is
     * treated as one) and then capped at {@see $maxPerPage} (when the cap is
     * enabled). One derivation shared by {@see window()} and
     * {@see fromBoundaries()}, so the limit a provider fetches and the size the
     * page advertises (in `meta.page.perPage` and the `page[size]=…` links)
     * always agree, even for garbage input — the symmetry the count-based
     * {@see PagePaginator::resolve()} also guarantees.
     */
    private function resolveSize(JsonApiRequestInterface $request): int
    {
        $size = \max(1, QueryParam::int($request->getPagination(), $this->sizeKey, $this->defaultSize));

        return $this->maxPerPage > 0 ? \min($size, $this->maxPerPage) : $size;
    }

    /**
     * Decodes the `page[<key>]` cursor token, if present, into a
     * {@see CursorBoundary}. Absent → null; a present but undecodable token →
     * {@see MalformedCursor} with the wire `page[<key>]` parameter name.
     *
     * @param array<string, mixed> $pagination
     */
    private function decodeBoundary(array $pagination, string $key): ?CursorBoundary
    {
        if (!isset($pagination[$key]) || !\is_string($pagination[$key]) || $pagination[$key] === '') {
            return null;
        }

        return $this->codec->decode($pagination[$key], "page[$key]");
    }
}
