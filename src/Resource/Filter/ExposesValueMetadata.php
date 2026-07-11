<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

use haddowg\JsonApi\Resource\Constraint\ConstraintInterface;

/**
 * The readonly **value-metadata consumption** surface shared by the value-carrying
 * filter *value objects* ({@see Where}, {@see Range}, {@see WhereIn}, …): reads the
 * declared value constraints and the OpenAPI description / example back off the
 * object's public readonly properties. The mirror mutable **authoring** surface
 * lives on the builders via {@see HasValueConstraints}.
 *
 * The generator reads {@see getDescription()} via an `instanceof DescribedFilter`
 * check and {@see constraints()} via the bare {@see FilterInterface}, so the access
 * stays type-safe rather than reaching into the trait.
 *
 * @property-read list<ConstraintInterface> $constraints
 * @property-read ?string                   $description
 * @property-read bool                      $hasExample
 * @property-read mixed                     $example
 */
trait ExposesValueMetadata
{
    /**
     * The declared value constraints, in declaration order.
     *
     * @return list<ConstraintInterface>
     */
    public function constraints(): array
    {
        return $this->constraints;
    }

    /**
     * The declared description surfaced by the OpenAPI generator, or `null`.
     */
    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * Whether an example value was declared (distinct from a declared `null`).
     */
    public function hasExample(): bool
    {
        return $this->hasExample;
    }

    /**
     * The declared example value; only meaningful when {@see hasExample()} is true.
     */
    public function getExample(): mixed
    {
        return $this->example;
    }
}
