<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Pagination;

/**
 * The slice of a collection a data layer must fetch for the current request,
 * derived from the `page[…]` parameters **before** any items are materialized.
 *
 * {@see PaginatorInterface::paginate()} deliberately takes pre-windowed items —
 * the page value objects never slice — so a data layer needs the strategy's
 * window first to push it down to its store (SQL `LIMIT`/`OFFSET`, an
 * `array_slice`, …). This interface is that strategy-shaped handoff: the
 * count-based strategies produce an {@see OffsetWindow}; a future cursor-capable
 * contract can produce a cursor-shaped window under the same seam. A data layer
 * narrows on the concrete window type(s) it knows how to execute.
 */
interface WindowInterface {}
