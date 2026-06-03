<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Sort\InMemory;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortHandlerInterface;
use haddowg\JsonApi\Resource\Sort\SortInterface;
use haddowg\JsonApi\Resource\Sort\UnsupportedSort;

/**
 * Reference {@see SortHandlerInterface} operating on a PHP `list<array|object>` via a
 * stable `usort`. For tests and worked examples; not a production sort layer.
 *
 * @implements SortHandlerInterface<list<mixed>>
 */
final class ArraySortHandler implements SortHandlerInterface
{
    public function apply(SortInterface $sort, mixed $query, bool $descending): mixed
    {
        if (!$sort instanceof SortByField) {
            throw new UnsupportedSort($sort);
        }

        if (!\is_array($query)) {
            $query = [];
        }

        /** @var list<mixed> $query */
        $column = $sort->column;
        \usort($query, static function (mixed $a, mixed $b) use ($column, $descending): int {
            $left = Accessor::get($a, $column);
            $right = Accessor::get($b, $column);
            $cmp = $left <=> $right;

            return $descending ? -$cmp : $cmp;
        });

        return $query;
    }
}
