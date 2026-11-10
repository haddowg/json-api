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

    protected function buildsToMany(): bool
    {
        return true;
    }

    /**
     * Positions the members by `$field`, a stored `Integer` field on the related type.
     * A replace writes each member's position from its index in the linkage, an add
     * appends new members after the highest position, and a remove leaves gaps. The
     * relation reads in position order, ascending, unless it declares a
     * {@see defaultSort()}. The field must be declared read-only on the related type,
     * since clients never set a position; {@see \haddowg\JsonApi\Serializer\RelationOrderValidator}
     * checks it, and that it exists, is stored and is an `Integer`, at warm-up.
     *
     * @return static
     */
    public function positionedBy(string $field): static
    {
        return $this->positionMembersBy($field);
    }

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
