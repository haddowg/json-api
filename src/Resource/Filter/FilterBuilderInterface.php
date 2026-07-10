<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * A **filter builder**: the mutable, fluent object an author chains to declare a
 * {@see FilterInterface}. {@see build()} freezes the accumulated declaration into
 * the readonly value object an adapter consumes.
 *
 * The resource **builds** any builder returned from `filters()` into its
 * {@see FilterInterface} before use, so an author may return either a builder or
 * an already-built filter.
 */
interface FilterBuilderInterface
{
    /**
     * Freezes the accumulated authoring state into the readonly value object the
     * adapter consumes. Pure and idempotent: it mutates nothing and returns an
     * equal filter each call.
     */
    public function build(): FilterInterface;
}
