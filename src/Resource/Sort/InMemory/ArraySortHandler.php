<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Sort\InMemory;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortHandlerInterface;
use haddowg\JsonApi\Resource\Sort\UnsupportedSort;

/**
 * Reference {@see SortHandlerInterface} operating on a PHP `list<array|object>`:
 * one `usort` whose comparator cascades through the directives in significance
 * order, so the request's first sort field is the primary key. For tests and
 * worked examples; not a production sort layer.
 *
 * @implements SortHandlerInterface<list<mixed>>
 */
final class ArraySortHandler implements SortHandlerInterface
{
    public function apply(array $sorts, mixed $query): mixed
    {
        /** @var list<array{string, bool}> $columns */
        $columns = [];
        foreach ($sorts as $directive) {
            $sort = $directive->sort;
            if (!$sort instanceof SortByField) {
                throw new UnsupportedSort($sort);
            }

            $columns[] = [$sort->column, $directive->descending];
        }

        if (!\is_array($query)) {
            $query = [];
        }

        if ($columns === []) {
            return $query;
        }

        /** @var list<mixed> $query */
        \usort($query, static function (mixed $a, mixed $b) use ($columns): int {
            foreach ($columns as [$column, $descending]) {
                $cmp = Accessor::get($a, $column) <=> Accessor::get($b, $column);
                if ($cmp !== 0) {
                    return $descending ? -$cmp : $cmp;
                }
            }

            return 0;
        });

        return $query;
    }
}
