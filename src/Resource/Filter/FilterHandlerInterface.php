<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * Executes a {@see Filter} against an adapter-native query context. The query
 * is `mixed` to avoid coupling core to any data layer — a Doctrine handler
 * narrows it to `QueryBuilder`, the in-memory reference handler narrows it to an
 * array, etc.
 *
 * @template TQuery
 */
interface FilterHandlerInterface
{
    /**
     * Applies `$filter` (with the request-supplied `$value`) to `$query`,
     * returning the modified query.
     *
     * @param TQuery $query
     * @return TQuery
     *
     * @throws UnsupportedFilter when this handler does not recognise `$filter`
     */
    public function apply(\haddowg\JsonApi\Resource\Filter\FilterInterface $filter, mixed $query, mixed $value): mixed;
}
