<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Pagination;

/**
 * The count-based fetch window: skip {@see $offset} items, take {@see $limit}.
 *
 * Every count-based strategy reduces to this shape ({@see PagePaginator} and
 * {@see FixedPagePaginator} derive `offset = (page - 1) * size`). Values are
 * normalised to `>= 0` at construction, so a data layer can hand them straight
 * to `LIMIT`/`OFFSET` (or `array_slice`) without re-validating; garbage
 * `page[…]` input therefore yields an empty window, mirroring the defensive
 * link-set suppression on the page value objects.
 */
final readonly class OffsetWindow implements WindowInterface
{
    public int $offset;

    public int $limit;

    public function __construct(int $offset, int $limit)
    {
        $this->offset = \max(0, $offset);
        $this->limit = \max(0, $limit);
    }
}
