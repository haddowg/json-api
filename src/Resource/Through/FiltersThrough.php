<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Through;

use haddowg\JsonApi\Resource\Filter\FilterInterface;

/**
 * A filter that can reach through relations. A data layer applies one with a path as
 * an `EXISTS`: a row matches when some row the path reaches matches {@see atLeaf()}
 * with {@see leafValue()}. A row whose relation reaches nothing never matches.
 */
interface FiltersThrough extends ReachesThrough, FilterInterface
{
    /**
     * The requested value as the leaf compares it: a wire id decoded through the reached
     * type's id encoder when the leaf is its id, a list of decoded ids for a list
     * filter, and otherwise the value unchanged.
     *
     * @throws \haddowg\JsonApi\Exception\FilterValueInvalid when an id cannot be decoded
     */
    public function leafValue(mixed $value): mixed;
}
