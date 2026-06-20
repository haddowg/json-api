<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter\InMemory;

use haddowg\JsonApi\Resource\Filter\FilterInterface;

/**
 * An extension "arm" for the reference {@see ArrayFilterHandler}: it teaches the
 * handler to execute ONE custom {@see FilterInterface} type the handler does not
 * recognise natively. Pass a list of arms to the handler's constructor; for any
 * filter none of its built-in arms match, it consults the registered arms (the
 * first whose {@see supports()} returns `true` wins) before raising
 * {@see \haddowg\JsonApi\Resource\Filter\UnsupportedFilter}.
 *
 * An arm returns a row {@see \Closure} predicate — the same in-memory model the
 * handler uses internally — so a custom filter composes with the built-ins and the
 * other declared filters exactly as a native one does. This is the in-memory twin
 * of a framework adapter's own arm (e.g. the bundle's Doctrine arm pushing the
 * predicate down to DQL); a portable custom filter ships both so it runs identically
 * on the in-memory witness and the real data store.
 */
interface ArrayFilterArmInterface
{
    /**
     * Whether this arm executes `$filter`. Keyed on the filter's concrete type
     * (`$filter instanceof MyFilter`), not its key — one arm backs one filter
     * value-object class.
     */
    public function supports(FilterInterface $filter): bool;

    /**
     * The row predicate for `$filter` against the request `$value`: returns `true`
     * to keep a row. Only called when {@see supports()} returned `true`.
     *
     * @return \Closure(mixed): bool
     */
    public function predicate(FilterInterface $filter, mixed $value): \Closure;
}
