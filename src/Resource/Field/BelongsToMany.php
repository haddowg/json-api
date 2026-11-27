<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * A pivot-backed to-many relationship (`belongsToMany`) — the built, readonly value
 * object the engine walks. Same serialization and constraint surface as
 * {@see HasMany}, plus **pivot-field declarations**: the fields of the join
 * (association) table, declared as real {@see FieldInterface} definitions — the same
 * field DSL used for attributes (`Integer`, `Str`, `DateTime`, …) with their
 * constraints, casts and read-only / context behaviour. Authors declare one with
 * {@see make()}, which returns a mutable {@see BelongsToManyBuilder}.
 *
 * One declaration drives every pivot concern: render (the field's value cast),
 * filter / sort (its name + column), and **write / validate** (its constraints
 * resolved by create/update context, and its {@see FieldInterface::isReadOnly()}
 * writability). Core carries the declarations and exposes them; it never writes
 * the join row itself — the Symfony bundle's Doctrine adapter owns that storage,
 * reading the field definitions back to validate the linkage `meta` and persist
 * the association entity.
 *
 * A pivot field may also be **computed** ({@see isComputedPivotField()}): declared with
 * `computed()` or `computedUsing()`, it has no column and its value comes from its hook. A
 * computed field is never written, and a relation whose pivot fields are all computed
 * needs no stored pivot at all ({@see storedPivotFields()}). A stored field with an
 * `extractUsing()` hook stays stored and writable and is read through the hook. Either way
 * the hook receives a {@see PivotEdge} rather than a join row ({@see hookedPivotValues()}).
 */
final readonly class BelongsToMany extends HasMany implements PivotRelationInterface
{
    use ReadsPivotFields;

    /**
     * @param array<string, FieldInterface> $pivotFields the declared pivot fields, keyed by field name (declaration order preserved)
     */
    public function __construct(
        FieldState $state,
        RelationState $relationState,
        private array $pivotFields = [],
        private ?string $pivotThrough = null,
        private ?string $pivotParentSide = null,
        private ?string $pivotRelatedSide = null,
    ) {
        parent::__construct($state, $relationState);
    }

    /**
     * The property or association of the {@see pivotThrough()} class pointing back at
     * the parent, as `through(parent:)` names it, or `null` when the adapter finds it.
     */
    public function pivotParentSide(): ?string
    {
        return $this->pivotParentSide;
    }

    /**
     * The property or association of the {@see pivotThrough()} class pointing at the
     * member, as `through(related:)` names it, or `null` when the adapter finds it.
     */
    public function pivotRelatedSide(): ?string
    {
        return $this->pivotRelatedSide;
    }

    /**
     * Declares a pivot-backed to-many relation to `$type`. Its members live in the pivot
     * rows, so `$foreignKey` must stay `null`; it is there only because `HasMany::make()`
     * takes one.
     *
     * @throws \InvalidArgumentException when a foreign key is given
     */
    public static function make(string $name, string $type, ?string $foreignKey = null): BelongsToManyBuilder
    {
        return BelongsToManyBuilder::make($name, $type, $foreignKey);
    }
}
