<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Sort;

/**
 * A sort is **metadata only**: a value object describing one allowed `sort`
 * key and the column it maps to. Execution lives in an adapter-provided
 * {@see SortHandler}. Mirrors the {@see \haddowg\JsonApi\Resource\Filter\FilterInterface}
 * metadata + handler split.
 */
interface SortInterface
{
    /**
     * The `sort` query-parameter key this sort responds to (without a leading
     * `-`, which denotes descending direction).
     */
    public function key(): string;
}
