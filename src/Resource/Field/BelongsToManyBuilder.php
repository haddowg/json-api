<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * The mutable **relation builder** for a pivot-backed {@see BelongsToMany} to-many
 * relationship. Adds the **pivot-field declarations** ({@see fields()}) and the
 * association-entity override ({@see through()}) on top of the {@see HasManyBuilder}
 * surface; {@see build()} freezes it — with its built pivot fields — into a readonly
 * {@see BelongsToMany} value object.
 */
final class BelongsToManyBuilder extends HasManyBuilder
{
    use DeclaresPivotFields;

    /**
     * Positions the members by the stored `Integer` pivot field `$field`. A replace
     * writes each member's position from its index in the linkage, an add appends new
     * members after the highest position, and a remove leaves gaps. The field becomes
     * read-only to clients and the relation's default read order, ascending, unless it
     * declares a {@see defaultSort()}. A field that isn't a declared, stored `Integer`
     * pivot field fails the build.
     *
     * @return static
     */
    public function positionedBy(string $field): static
    {
        return $this->positionMembersBy($field);
    }

    public function build(): BelongsToMany
    {
        return new BelongsToMany($this->fieldState(), $this->relationState(), $this->positionedPivotFields(), $this->pivotThrough);
    }
}
