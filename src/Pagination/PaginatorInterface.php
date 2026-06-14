<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Pagination;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * Reads the `page[…]` query parameters from a request and produces the matching
 * {@see Page} for a count-based pagination strategy.
 *
 * Implemented by {@see PagePaginator}, {@see OffsetPaginator} and
 * {@see FixedPagePaginator} (the strategies whose page boundaries derive from a
 * known total item count). Cursor pagination has a distinct shape — its
 * boundaries are caller-supplied cursors, not a total — so {@see CursorPaginator}
 * is a standalone fluent strategy that does not implement this interface.
 * Consumers can supply their own count-based strategy by implementing this
 * contract and returning whatever {@see Page} subtype is appropriate.
 *
 * @see https://jsonapi.org/format/1.1/#fetching-pagination
 */
interface PaginatorInterface
{
    /**
     * The fetch window this strategy derives from the request's `page[…]`
     * parameters, exposed **before** any items are materialized so a data layer
     * can push it down to its store (SQL `LIMIT`/`OFFSET`, an `array_slice`, …).
     * The count-based strategies return an {@see OffsetWindow}; {@see paginate()}
     * then expects exactly the items of that window.
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
}
