<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Through;

use haddowg\JsonApi\Resource\Sort\SortInterface;

/**
 * A sort that can reach through to-one relations. A data layer orders by the leaf
 * field of the row the path reaches, and by `null` when a relation reaches nothing,
 * under the same nulls rule as a field of the sorted type.
 */
interface SortsThrough extends ReachesThrough, SortInterface {}
