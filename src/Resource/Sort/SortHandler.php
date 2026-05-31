<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Sort;

/**
 * Executes a {@see Sort} against an adapter-native query context. The query is
 * `mixed` to avoid coupling core to any data layer.
 *
 * @template TQuery
 */
interface SortHandler
{
    /**
     * Applies `$sort` in the given direction to `$query`, returning the modified
     * query.
     *
     * @param TQuery $query
     * @param bool   $descending true for `-key` (descending), false for ascending
     * @return TQuery
     *
     * @throws UnsupportedSort when this handler does not recognise `$sort`
     */
    public function apply(Sort $sort, mixed $query, bool $descending): mixed;
}
