<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Collection;

/**
 * A {@see CollectionResult} for a keyset (cursor) page: the windowed items plus
 * the boundary cursor tokens the executing provider minted for them.
 *
 * Count-free like a count-free offset page ({@see $total} null, {@see $windowed}
 * true) — a cursor strategy never derives a total — but additionally carries the
 * already-encoded {@see $cursorBefore} / {@see $cursorAfter} tokens (the `prev` /
 * `next` boundaries: the first / last row of the page) and {@see $hasPrevious}.
 * {@see CollectionResult::$hasMore} continues to mean "a further page follows
 * forward" (drives `next`); {@see $hasPrevious} is its backward twin (drives
 * `prev`). The handler narrows on this subtype to build a
 * {@see \haddowg\JsonApi\Pagination\CursorBasedPage} from the boundary tokens,
 * so the offset {@see CollectionResult} path stays byte-identical.
 *
 * Only the provider can produce the tokens (it owns the row → boundary-value
 * reader), so they arrive pre-encoded here rather than being derived from the
 * items.
 *
 * @template-covariant TEntity of object
 *
 * @extends CollectionResult<TEntity>
 */
class CursorCollectionResult extends CollectionResult
{
    /**
     * @param iterable<TEntity> $items        the windowed page items
     * @param ?string           $cursorBefore the encoded `prev` boundary (the first row's cursor), or null when there is no previous page
     * @param ?string           $cursorAfter  the encoded `next` boundary (the last row's cursor), or null when there is no following page
     * @param bool              $hasPrevious  whether a previous page exists (drives the `prev` link)
     * @param bool              $hasMore      whether a following page exists (drives the `next` link)
     */
    public function __construct(
        iterable $items,
        public readonly ?string $cursorBefore = null,
        public readonly ?string $cursorAfter = null,
        public readonly bool $hasPrevious = false,
        bool $hasMore = false,
    ) {
        parent::__construct($items, total: null, windowed: true, hasMore: $hasMore);
    }
}
