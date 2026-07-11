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
    /**
     * The declared pivot fields, keyed by field name (declaration order preserved),
     * already built into their value objects.
     *
     * @var array<string, FieldInterface>
     */
    private array $pivotFields = [];

    private ?string $pivotThrough = null;

    public function build(): BelongsToMany
    {
        return new BelongsToMany($this->fieldState(), $this->relationState(), $this->pivotFields, $this->pivotThrough);
    }

    /**
     * Declares the pivot (join-table) fields as field definitions. Pass the same
     * field types used for attributes — `Integer::make('position')->required()`,
     * `DateTime::make('addedAt')->readOnly()`, `Str::make('note')->maxLength(140)`
     * — with their constraints, casts and read-only / context behaviour. Each entry
     * may be a field builder or an already-built field; any builder is **built**
     * here, so an author need not call `->build()` on a pivot child by hand. A pivot
     * field is **writable by default** (settable from the linkage `meta`); opt a
     * server-owned column out with `->readOnly()`. Replaces any previously declared
     * set.
     *
     * @return static
     */
    public function fields(FieldInterface|FieldBuilderInterface ...$fields): static
    {
        $this->pivotFields = [];
        foreach ($fields as $field) {
            $built = $field instanceof FieldBuilderInterface ? $field->build() : $field;
            $this->pivotFields[$built->name()] = $built;
        }

        return $this;
    }

    /**
     * Names the association entity backing the pivot. Declare-only in 1.0:
     * an opaque class-string the host interprets (the Symfony bundle's Doctrine
     * adapter reads it as the association entity backing the pivot relation,
     * overriding its auto-detection). Core never interprets it. Pass `null` to
     * clear an earlier override.
     *
     * @return static
     */
    public function through(?string $associationEntity): static
    {
        $this->pivotThrough = $associationEntity;

        return $this;
    }
}
