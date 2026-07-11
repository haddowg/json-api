<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * A server-composed **AND** filter group: a row matches when it satisfies
 * **every** child filter. The group's request value is passed to each child, so
 * `WhereAll::make('inStockCheap', LessThan::make('price')->fixed(10),
 * Boolean::make('inStock')->fixed(true))` is a canned toggle
 * (`price < 10 AND inStock = true`), while value-carrying children would AND a
 * fanned value across columns.
 *
 * A thin {@see WhereGroup} subclass; a handler dispatches it with an
 * `instanceof WhereAll` arm that combines its children's predicates with `&&`.
 * Groups may nest — a {@see WhereAny} child inside a `WhereAll` yields
 * `A AND (B OR C)`. Authors declare one with {@see make()}, which returns a mutable
 * {@see WhereAllBuilder}.
 */
final readonly class WhereAll extends \haddowg\JsonApi\Resource\Filter\WhereGroup
{
    public static function make(string $key, \haddowg\JsonApi\Resource\Filter\FilterInterface|\haddowg\JsonApi\Resource\Filter\FilterBuilderInterface ...$children): WhereAllBuilder
    {
        return WhereAllBuilder::make($key, ...$children);
    }
}
