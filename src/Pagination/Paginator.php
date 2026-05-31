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
interface Paginator
{
    /**
     * @param iterable<mixed> $items
     *
     * @return Page<mixed>
     */
    public function paginate(JsonApiRequestInterface $request, iterable $items, int $totalItems): Page;
}
