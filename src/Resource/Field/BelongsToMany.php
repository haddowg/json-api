<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * A pivot-backed to-many relationship (`belongsToMany`). Same serialization and
 * constraint surface as {@see HasMany}, plus **pivot-field declarations**: the
 * fields of the join (association) table, declared as real
 * {@see FieldInterface} definitions — the same field DSL used for attributes
 * (`Integer`, `Str`, `DateTime`, …) with their constraints, casts and
 * read-only / context behaviour.
 *
 * One declaration drives every pivot concern: render (the field's value cast),
 * filter / sort (its name + column), and **write / validate** (its constraints
 * resolved by create/update context, and its {@see FieldInterface::isReadOnly()}
 * writability). Core carries the declarations and exposes them; it never writes
 * the join row itself — the Symfony bundle's Doctrine adapter owns that storage,
 * reading the field definitions back to validate the linkage `meta` and persist
 * the association entity.
 */
final class BelongsToMany extends HasMany
{
    /**
     * The declared pivot fields, keyed by field name (declaration order
     * preserved).
     *
     * @var array<string, FieldInterface>
     */
    private array $pivotFields = [];

    private ?string $pivotThrough = null;

    /**
     * Declares the pivot (join-table) fields as field definitions. Pass the same
     * field types used for attributes — `Integer::make('position')->required()`,
     * `DateTime::make('addedAt')->readOnly()`, `Str::make('note')->maxLength(140)`
     * — with their constraints, casts and read-only / context behaviour. A pivot
     * field is **writable by default** (settable from the linkage `meta`); opt a
     * server-owned column out with `->readOnly()`. Replaces any previously
     * declared set.
     *
     * @return static
     */
    public function fields(FieldInterface ...$fields): static
    {
        $this->pivotFields = [];
        foreach ($fields as $field) {
            $this->pivotFields[$field->name()] = $field;
        }

        return $this;
    }

    /**
     * The declared pivot fields, as a list of {@see FieldInterface} definitions.
     *
     * @return list<FieldInterface>
     */
    public function pivotFields(): array
    {
        return \array_values($this->pivotFields);
    }

    /**
     * The declared pivot field named `$name`, or `null` when none is declared.
     */
    public function pivotField(string $name): ?FieldInterface
    {
        return $this->pivotFields[$name] ?? null;
    }

    /**
     * The pivot fields **writable** in the given operation context — those not
     * read-only there ({@see FieldInterface::isReadOnly()} resolved by create vs
     * update). These are the fields a host may set from the linkage `meta`; a
     * read-only field is never written from `meta` (it takes its server-owned
     * value). Declaration order is preserved.
     *
     * @param bool $creating true for a create (POST) request, false for update (PATCH)
     * @return list<FieldInterface>
     */
    public function writablePivotFields(bool $creating): array
    {
        return \array_values(\array_filter(
            $this->pivotFields,
            static fn(FieldInterface $field): bool => $field->isReadOnly($creating) === false,
        ));
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

    /**
     * The declared pivot association entity (the `through()` override), or
     * `null` when none was declared.
     */
    public function pivotThrough(): ?string
    {
        return $this->pivotThrough;
    }
}
