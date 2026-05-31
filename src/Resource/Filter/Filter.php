<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * A filter is **metadata only**: a value object describing one `filter[...]`
 * parameter's intent (key, target column, operator, …). It carries no
 * behaviour — execution lives in an adapter-provided {@see FilterHandler} that
 * translates the value object into the data layer's native query operations.
 * Core ships the value objects plus a reference in-memory handler; database
 * handlers (Doctrine, etc.) live in framework adapters.
 *
 * Mirrors the {@see \haddowg\JsonApi\Resource\Constraint\Constraint} metadata +
 * adapter-translator pattern.
 */
interface Filter
{
    /**
     * The `filter[<key>]` query-parameter key this filter responds to.
     */
    public function key(): string;
}
