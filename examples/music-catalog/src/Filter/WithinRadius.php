<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Examples\MusicCatalog\Filter;

use haddowg\JsonApi\Resource\Filter\FilterInterface;

/**
 * A worked custom {@see FilterInterface}: a geo "within radius" predicate the
 * reference {@see \haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterHandler}
 * does not know how to execute. The catalog's
 * {@see \haddowg\JsonApi\Examples\MusicCatalog\Data\CriteriaApplier} carries the
 * matching execution arm — the metadata/handler split for filters, exactly as a
 * Doctrine adapter would add an arm of its own.
 *
 * Metadata only: it names the latitude/longitude columns to read off each row.
 * The request value is the `{lat, lng, km}` centre + radius.
 */
final readonly class WithinRadius implements FilterInterface
{
    public function __construct(
        public string $key,
        public string $latColumn,
        public string $lngColumn,
    ) {}

    public static function make(string $key, string $latColumn = 'latitude', string $lngColumn = 'longitude'): self
    {
        return new self($key, $latColumn, $lngColumn);
    }

    public function key(): string
    {
        return $this->key;
    }
}
