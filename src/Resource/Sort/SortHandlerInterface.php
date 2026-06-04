<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Sort;

/**
 * Executes a requested sort order against an adapter-native query context. The
 * query is `mixed` to avoid coupling core to any data layer.
 *
 * The whole ordered sort — a list of {@see SortDirective}s, most significant
 * first — is applied in **one** call, never folded directive by directive:
 * sorting does not compose commutatively, and the correct way to combine keys
 * differs per data layer (SQL appends `ORDER BY` terms in significance order;
 * an in-memory re-sort must instead compare keys in a single cascading
 * comparator). Handing the handler the full list lets each adapter compose
 * natively and keeps the request's first sort field the primary key
 * everywhere, as the JSON:API spec requires.
 *
 * @template TQuery
 */
interface SortHandlerInterface
{
    /**
     * Applies the requested sort order — most significant directive first — to
     * `$query`, returning the modified query.
     *
     * @param list<SortDirective> $sorts
     * @param TQuery              $query
     *
     * @return TQuery
     *
     * @throws UnsupportedSort when a directive's sort is not recognised by this handler
     */
    public function apply(array $sorts, mixed $query): mixed;
}
