<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Through;

/**
 * What a filter or sort reaching through relations reads on the reached type.
 *
 * @internal
 */
enum LeafKind
{
    /** A stored attribute, or the id, compared by value. */
    case Value;

    /** The reached type's id, compared as wire ids decoded through its encoder. */
    case Id;

    /** One of the reached type's relations, tested for whether it holds a row. */
    case Relation;

    /** A stored attribute, or the id, ordered by, through to-one relations only. */
    case Sort;
}
