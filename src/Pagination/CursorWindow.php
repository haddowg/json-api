<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Pagination;

/**
 * The keyset (cursor) fetch window: take up to {@see $limit} items relative to
 * the decoded {@see $after} / {@see $before} boundaries.
 *
 * The cursor twin of {@see OffsetWindow} under the same {@see WindowInterface}
 * seam. It is **count-free by design** — a keyset page never derives a total —
 * and deliberately carries only the limit and the decoded boundaries, **not** a
 * resolved sort spec: the active sort lives on the request's
 * {@see \haddowg\JsonApi\Collection\CollectionCriteria}, and the executing
 * provider (C2/C3) resolves it to keyset columns there, checks the cursor's
 * columns against it (throwing
 * {@see \haddowg\JsonApi\Exception\StaleCursor} on a mismatch), and runs the
 * keyset WHERE. C1 only shapes the window.
 *
 * At most one boundary is set on a request: `page[before]` wins over
 * `page[after]` when both are supplied (the provider applies the precedence).
 */
final readonly class CursorWindow implements WindowInterface
{
    public int $limit;

    /**
     * @param int             $limit  items to fetch (normalised to `>= 1`; a keyset page always returns at least one row's worth of window)
     * @param ?CursorBoundary $after  the decoded `page[after]` boundary, or null
     * @param ?CursorBoundary $before the decoded `page[before]` boundary, or null
     */
    public function __construct(
        int $limit,
        public ?CursorBoundary $after = null,
        public ?CursorBoundary $before = null,
    ) {
        $this->limit = \max(1, $limit);
    }
}
