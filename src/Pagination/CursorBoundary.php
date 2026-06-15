<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Pagination;

/**
 * The decoded contents of a single cursor token: the boundary row's value for
 * each active-sort column (plus the primary-key tiebreaker key), and the
 * direction the cursor points.
 *
 * {@see $values} is keyed by sort **column** (the wire column name the provider
 * resolves the active sort to), each value a JSON-safe scalar or `null` — a
 * `null` is a legitimate boundary value for a nullable sort column (the keyset
 * execution in C2/C3 handles the IS-NULL branch; C1 only carries it). The map
 * always includes the PK key so the keyset has a total order even when the
 * active-sort columns tie.
 *
 * {@see $pointsToNextItems} records whether the token was minted to page
 * **forward** (the `next`/`page[after]` cursor, the last row of a page) or
 * **backward** (the `prev`/`page[before]` cursor, the first row): the executing
 * provider reads it to orient the keyset comparison. C1 defines and round-trips
 * it through the codec; the provider consumes it.
 */
final readonly class CursorBoundary
{
    /**
     * @param array<string, scalar|null> $values             boundary value per sort column (incl. the PK key); null allowed for a nullable column
     * @param bool                        $pointsToNextItems  whether this is a forward (`page[after]`) cursor; false for a backward (`page[before]`) cursor
     */
    public function __construct(
        public array $values,
        public bool $pointsToNextItems,
    ) {}
}
