<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * What a {@see WhereSet} value asks of its column, read by {@see WhereSet::match()}.
 */
enum WhereSetMatch
{
    /** `true` alone: the column IS NOT NULL. */
    case Set;

    /** `false` alone: the column IS NULL. */
    case NotSet;

    /** Both `true` and `false`: every row, so the filter adds no condition. */
    case Either;

    /** An empty list: no row. */
    case Neither;
}
