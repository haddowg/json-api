<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Examples\MusicCatalog\Filter;

use haddowg\JsonApi\Resource\Constraint\ConstraintInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\HasValueConstraints;

/**
 * A worked custom {@see FilterInterface}: a geo "within radius" predicate the
 * reference {@see \haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterHandler}
 * does not know how to execute. The catalog's
 * {@see \haddowg\JsonApi\Examples\MusicCatalog\Data\CriteriaApplier} carries the
 * matching execution arm — the metadata/handler split for filters, exactly as a
 * Doctrine adapter would add an arm of its own.
 *
 * Metadata only: it names the latitude/longitude columns to read off each row.
 * The request value is the `{lat, lng, km}` centre + radius. Reusing
 * {@see HasValueConstraints} shows a custom filter inherits the same `constrain()`
 * / type-shortcut value-constraint vocabulary as the built-ins.
 */
final readonly class WithinRadius implements FilterInterface
{
    use HasValueConstraints;

    /**
     * @param list<ConstraintInterface> $constraints declared value constraints
     */
    public function __construct(
        public string $key,
        public string $latColumn,
        public string $lngColumn,
        public array $constraints = [],
    ) {}

    public static function make(string $key, string $latColumn = 'latitude', string $lngColumn = 'longitude'): self
    {
        return new self($key, $latColumn, $lngColumn);
    }

    public function key(): string
    {
        return $this->key;
    }

    /**
     * @param list<ConstraintInterface> $constraints
     */
    protected function withConstraints(array $constraints): static
    {
        return new self($this->key, $this->latColumn, $this->lngColumn, $constraints);
    }
}
