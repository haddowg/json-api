<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

use haddowg\JsonApi\Resource\Constraint\ConstraintInterface;

/**
 * A filter is **metadata only**: a value object describing one `filter[...]`
 * parameter's intent (key, target column, operator, …). It carries no
 * behaviour — execution lives in an adapter-provided {@see FilterHandler} that
 * translates the value object into the data layer's native query operations.
 * Core ships the value objects plus a reference in-memory handler; database
 * handlers (Doctrine, etc.) live in framework adapters.
 *
 * Mirrors the {@see \haddowg\JsonApi\Resource\Constraint\ConstraintInterface} metadata +
 * adapter-translator pattern.
 */
interface FilterInterface
{
    /**
     * The `filter[<key>]` query-parameter key this filter responds to.
     */
    public function key(): string;

    /**
     * The **value constraints** declared on this filter (default `[]`). Like every
     * other {@see ConstraintInterface}, these are metadata only — core never
     * executes them; a framework adapter translates them to its native validator
     * and checks a client-supplied `filter[<key>]` value **before** the filter
     * reaches the data layer, so a mistyped value is a clean `400`
     * {@see \haddowg\JsonApi\Exception\FilterValueInvalid} rather than a data-layer
     * crash. Value-carrying filters declare them with the {@see HasValueConstraints}
     * `constrain()` / `numeric()` / `integer()` / `uuid()` / `boolean()` /
     * `pattern()` builders; presence-only filters return `[]`.
     *
     * @return list<ConstraintInterface>
     */
    public function constraints(): array;
}
