<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * A filter that can declare a **default value**: when the request does not
 * carry its `filter[<key>]` parameter, the filter applies as if the client had
 * sent the default — and a request that does carry the key always wins, so a
 * default is a convenience the client can override, never a constraint it
 * cannot (an unremovable base constraint belongs to the data layer, not the
 * filter vocabulary).
 *
 * The value-carrying built-ins ({@see Where}, {@see WhereIn}, {@see WhereNotIn},
 * {@see WhereIdIn}, {@see WhereIdNotIn}) implement this via their `default()`
 * refinement helper. The presence-only filters ({@see WhereNull},
 * {@see WhereHas}, …) deliberately do not: their requested *presence* is their
 * whole semantics, so a "default" would be indistinguishable from always-on.
 *
 * {@see FilterDefaults::apply()} is the one place the folding semantics live —
 * adapters fold the defaults into the requested map through it rather than
 * re-deciding presence rules per data layer.
 */
interface HasDefaultValue
{
    /**
     * Whether a default was declared. A dedicated flag, not a `null` sentinel —
     * `null` is a legitimate default value.
     */
    public function hasDefault(): bool;

    /**
     * The value to apply when the request omits the filter's key. Shaped
     * exactly as the request would carry it (a set filter's default may be a
     * delimited string or an array, per its `delimiter()` declaration).
     */
    public function defaultValue(): mixed;
}
