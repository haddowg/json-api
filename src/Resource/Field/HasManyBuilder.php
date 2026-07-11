<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Resource\Constraint\MaxItems;
use haddowg\JsonApi\Resource\Constraint\MinItems;

/**
 * The mutable **relation builder** for a {@see HasMany} to-many relationship. Adds
 * the collection-cardinality helpers ({@see minItems()} / {@see maxItems()}) on top
 * of the common {@see AbstractRelationBuilder} surface; {@see build()} freezes it
 * into a readonly {@see HasMany} value object.
 *
 * Non-final by design: {@see BelongsToManyBuilder} extends it (it builds a
 * pivot-backed {@see BelongsToMany}).
 */
class HasManyBuilder extends AbstractRelationBuilder
{
    use DeclaresMonomorphicType;

    public function build(): HasMany
    {
        return new HasMany($this->fieldState(), $this->relationState());
    }

    /**
     * @return static
     */
    public function minItems(int $count): static
    {
        return $this->addConstraint(new MinItems($count, $this->currentContext()));
    }

    /**
     * @return static
     */
    public function maxItems(int $count): static
    {
        return $this->addConstraint(new MaxItems($count, $this->currentContext()));
    }
}
