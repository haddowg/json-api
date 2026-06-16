<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Examples\MusicCatalog\Sort;

use haddowg\JsonApi\Resource\Sort\SortInterface;

/**
 * A worked computed {@see SortInterface}: orders artists by their `trackCount`,
 * which is not a {@see \haddowg\JsonApi\Resource\Sort\SortByField}. The reference
 * {@see \haddowg\JsonApi\Resource\Sort\InMemory\ArraySortHandler} only understands
 * `SortByField`, so the catalog's
 * {@see \haddowg\JsonApi\Examples\MusicCatalog\Data\CriteriaApplier} carries a
 * pre-arm that executes this sort before delegating — the metadata/handler split
 * for sorts.
 */
final readonly class TrackCountSort implements SortInterface
{
    public function __construct(
        public string $key = 'trackCount',
        public string $column = 'trackCount',
    ) {}

    public function key(): string
    {
        return $this->key;
    }
}
