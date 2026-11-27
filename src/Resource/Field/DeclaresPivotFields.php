<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * The pivot-field declarations a {@see BelongsToManyBuilder} and a
 * {@see MorphToManyBuilder} share: the fields of the link row ({@see fields()}) and the
 * class backing it ({@see through()}).
 */
trait DeclaresPivotFields
{
    /**
     * The declared pivot fields, keyed by field name (declaration order preserved),
     * already built into their value objects.
     *
     * @var array<string, FieldInterface>
     */
    protected array $pivotFields = [];

    protected ?string $pivotThrough = null;

    protected ?string $pivotParentSide = null;

    protected ?string $pivotRelatedSide = null;

    /**
     * Declares the pivot (join-table) fields as field definitions. Pass the same
     * field types used for attributes — `Integer::make('position')->required()`,
     * `DateTime::make('addedAt')->readOnly()`, `Str::make('note')->maxLength(140)`
     * — with their constraints, casts and read-only / context behaviour. Each entry
     * may be a field builder or an already-built field; any builder is **built**
     * here, so an author need not call `->build()` on a pivot child by hand. A pivot
     * field is **writable by default** (settable from the linkage `meta`); opt a
     * server-owned column out with `->readOnly()`. `meta.pivot` is closed to members no
     * field names unless one entry is an `AdditionalProperties::make()`, whose
     * `extractUsing()` hook receives the {@see PivotEdge} and returns extra members to
     * render, and whose `fillUsing()` hook receives the pivot row and the undeclared
     * members written. Replaces any previously declared set.
     *
     * @return static
     */
    public function fields(FieldInterface|FieldBuilderInterface ...$fields): static
    {
        $this->pivotFields = [];
        $this->pivotAdditional = null;
        foreach ($fields as $field) {
            $built = $field instanceof FieldBuilderInterface ? $field->build() : $field;
            if ($built instanceof AdditionalProperties) {
                if ($this->pivotAdditional !== null) {
                    throw new \LogicException(\sprintf('Relation "%s" declares more than one additionalProperties entry among its pivot fields; declare at most one.', $this->name));
                }
                $this->pivotAdditional = $built;

                continue;
            }
            $this->pivotFields[$built->name()] = $built;
        }

        return $this;
    }

    /**
     * Names the class backing the link rows: an opaque class-string the host
     * interprets, such as the association entity a Doctrine adapter reads in place of
     * the one it would detect. Core never interprets it. Pass `null` to clear an
     * earlier override, along with any side names.
     *
     * `$parent` and `$related` name the class's property or association on each side
     * of a link row: the one pointing back at the parent, and the one pointing at the
     * member. They are opaque too, and an adapter uses them when the class alone can't
     * tell its sides apart, such as a row linking a type to itself:
     * `->through(Seat::class, parent: 'owner', related: 'peer')`.
     *
     * @return static
     *
     * @throws \InvalidArgumentException when a side is named without a class, is empty, or both sides name the same member
     */
    public function through(?string $associationEntity, ?string $parent = null, ?string $related = null): static
    {
        $problem = match (true) {
            $associationEntity === null && ($parent !== null || $related !== null) => 'names a side of its link rows without naming their class',
            $parent === '' || $related === '' => 'names a side of its link rows with an empty string',
            $parent !== null && $parent === $related => \sprintf('names "%s" as both the parent and the related side of its link rows', $parent),
            default => null,
        };
        if ($problem !== null) {
            throw new \InvalidArgumentException(\sprintf('Relation "%s" %s.', $this->name, $problem));
        }

        $this->pivotThrough = $associationEntity;
        $this->pivotParentSide = $parent;
        $this->pivotRelatedSide = $related;

        return $this;
    }

    /**
     * The declared pivot fields, with the field the relation is positioned by made
     * read-only to every write: the server assigns positions, so a client's
     * `meta.pivot` never sets one.
     *
     * @return array<string, FieldInterface>
     *
     * @throws \LogicException when the position field is not a declared, stored
     *                         `Integer` pivot field
     */
    protected function positionedPivotFields(): array
    {
        if ($this->positionField === null) {
            return $this->pivotFields;
        }

        $field = $this->pivotFields[$this->positionField] ?? null;
        $problem = match (true) {
            $field === null => 'it declares no such pivot field',
            !$field instanceof Integer => 'it is not an Integer field',
            $field->column() === null => 'it is computed, not stored',
            default => null,
        };
        if ($problem !== null || !$field instanceof Integer) {
            throw new \LogicException(\sprintf(
                'Relation "%s" is positioned by "%s", but %s. A position is a stored Integer pivot field.',
                $this->name,
                $this->positionField,
                $problem,
            ));
        }

        $fields = $this->pivotFields;
        $fields[$this->positionField] = $field->asPosition();

        return $fields;
    }
}
