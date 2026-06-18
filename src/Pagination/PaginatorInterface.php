<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Pagination;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * Reads the `page[…]` query parameters from a request and produces the matching
 * {@see Page} for a count-based pagination strategy.
 *
 * Implemented by {@see PagePaginator}, {@see OffsetPaginator} and
 * {@see FixedPagePaginator} (the count-based strategies, whose page boundaries
 * derive from a known total item count) and by {@see CursorPaginator} (the
 * keyset strategy). It is therefore the single pagination seam: {@see window()}
 * may return any {@see WindowInterface} — an {@see OffsetWindow} for the
 * count-based strategies or a {@see CursorWindow} for cursor — and a data layer
 * narrows on the concrete window type it knows how to execute. Cursor pagination
 * is inherently **count-free**: its {@see paginate()} ignores the `$totalItems`
 * argument (a keyset page never derives a total), and its real builder takes the
 * caller-minted boundary cursors directly ({@see CursorPaginator::fromBoundaries()}),
 * so the cursor path runs through {@see paginateWithoutCount()}-shaped, count-free
 * book-keeping. Consumers can supply their own strategy by implementing this
 * contract and returning whatever {@see Page} subtype is appropriate.
 *
 * @see https://jsonapi.org/format/1.1/#fetching-pagination
 */
interface PaginatorInterface
{
    /**
     * The fetch window this strategy derives from the request's `page[…]`
     * parameters, exposed **before** any items are materialized so a data layer
     * can push it down to its store (SQL `LIMIT`/`OFFSET`, a keyset `WHERE`, an
     * `array_slice`, …). The count-based strategies return an {@see OffsetWindow}
     * and {@see paginate()} then expects exactly the items of that window; the
     * cursor strategy returns a {@see CursorWindow} (the decoded keyset boundaries
     * + limit) which a keyset-capable data layer executes instead.
     */
    public function window(JsonApiRequestInterface $request): \haddowg\JsonApi\Pagination\WindowInterface;

    /**
     * Builds the page for the **pre-windowed** `$items` (the slice described by
     * {@see window()} — pages never slice) and the separately-computed
     * `$totalItems` of the whole filtered collection.
     *
     * @param iterable<mixed> $items
     *
     * @return \haddowg\JsonApi\Pagination\PageInterface<mixed>
     */
    public function paginate(JsonApiRequestInterface $request, iterable $items, int $totalItems): \haddowg\JsonApi\Pagination\PageInterface;

    /**
     * Builds the page **without a total count** — the "do not count" mode — for the
     * pre-windowed `$items`. The total of the whole collection is left unknown, so
     * the page omits `meta.page.total` and the `last` link; whether a further page
     * follows is signalled by `$hasMore` (a data layer typically fetches one item
     * past the window to determine this), which drives the `next` link. This lets a
     * data layer paginate a non-countable related collection without ever running a
     * `COUNT` query. The cursor strategy is inherently count-free and so is not a
     * {@see PaginatorInterface}; this brings the same shape to the count-based
     * strategies on demand.
     *
     * @param iterable<mixed> $items
     *
     * @return \haddowg\JsonApi\Pagination\PageInterface<mixed>
     */
    public function paginateWithoutCount(JsonApiRequestInterface $request, iterable $items, bool $hasMore): \haddowg\JsonApi\Pagination\PageInterface;

    /**
     * Whether this strategy runs the `COUNT` on every paged request (the author
     * opt-in: count-free by default, `true` only when {@see PagePaginator::withCount()}
     * (or its sibling on the other count-based strategies) flipped it; the cursor
     * strategy is inherently count-free and returns `false`). The bundle handler
     * reads this to decide {@see paginate()} vs {@see paginateWithoutCount()} and
     * whether to issue the `COUNT`.
     */
    public function wantsCount(): bool;
}
