<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * A server-composed **OR** filter group: a row matches when it satisfies **any**
 * child filter. The group's request value is passed to each child, so
 * `WhereAny::make('q', Contains::make('name'), Contains::make('email'))` is a
 * multi-column search (`filter[q]=foo` → `name LIKE foo OR email LIKE foo`) — one
 * value fanned across columns — while {@see WhereBuilder::fixed() fixed} children
 * give a canned "any of these conditions" toggle.
 *
 * A thin {@see WhereGroup} subclass; a handler dispatches it with an
 * `instanceof WhereAny` arm that combines its children's predicates with `||`.
 * Groups may nest — a {@see WhereAll} child inside a `WhereAny` yields
 * `A OR (B AND C)`. Authors declare one with {@see make()}, which returns a mutable
 * {@see WhereAnyBuilder}.
 */
final readonly class WhereAny extends \haddowg\JsonApi\Resource\Filter\WhereGroup
{
    public static function make(string $key, \haddowg\JsonApi\Resource\Filter\FilterInterface|\haddowg\JsonApi\Resource\Filter\FilterBuilderInterface ...$children): WhereAnyBuilder
    {
        return WhereAnyBuilder::make($key, ...$children);
    }
}
