<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Sort\InMemory;

use haddowg\JsonApi\Resource\Sort\SortInterface;

/**
 * An extension "arm" for the reference {@see ArraySortHandler}: it teaches the
 * handler to order by ONE custom {@see SortInterface} type it does not recognise
 * natively (the built-in handler sorts only by a declared field). Pass a list of
 * arms to the handler's constructor; for a directive whose sort no built-in arm
 * matches, the handler consults the registered arms (first {@see supports()} match
 * wins) before raising {@see \haddowg\JsonApi\Resource\Sort\UnsupportedSort}.
 *
 * An arm contributes a per-row sort KEY rather than a standalone ordering, so a
 * custom sort weaves into the handler's lexicographic multi-key cascade in the
 * request's significance order — a custom directive can be primary, secondary, or a
 * tie-breaker alongside field sorts, exactly like a native one. This is the
 * in-memory twin of a framework adapter's own arm (e.g. the bundle's Doctrine arm
 * appending an `ORDER BY`); a portable custom sort ships both.
 */
interface ArraySortArmInterface
{
    /**
     * Whether this arm orders by `$sort`. Keyed on the sort's concrete type
     * (`$sort instanceof MySort`) — one arm backs one sort value-object class.
     */
    public function supports(SortInterface $sort): bool;

    /**
     * The sort key for `$row` under `$sort`, compared between rows with `<=>` (the
     * handler applies the directive's ascending/descending direction). Only called
     * when {@see supports()} returned `true`.
     */
    public function value(SortInterface $sort, mixed $row): mixed;
}
