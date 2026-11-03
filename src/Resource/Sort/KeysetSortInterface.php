<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Sort;

/**
 * A custom sort that can order a cursor (keyset) page. Without it only a
 * {@see SortByField} can, and any other sort under cursor pagination is a 500
 * {@see UnsupportedSort}.
 *
 * Core reads the value a cursor token carries through {@see keysetValue()}; the
 * data layer's own arm for the sort supplies the ordering and the strictly-after
 * comparison against that value, with null ordered as the largest value like a
 * keyset column.
 */
interface KeysetSortInterface extends SortInterface
{
    /**
     * The value `$row` sorts by under this sort, written into the cursor token of a
     * page boundary row. Return a scalar, null, a {@see \DateTimeInterface}, a
     * {@see \Stringable} or a backed enum, coerced to the wire form by
     * {@see \haddowg\JsonApi\Collection\Keyset\CursorTokenMinter::coerce()}; the
     * data layer compares that wire form to its own sort expression.
     */
    public function keysetValue(mixed $row): mixed;
}
