<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * A to-many relation whose links carry fields of their own, declared with the field DSL
 * on the relation builder's `fields()`: a {@see BelongsToMany} over a join table, or a
 * {@see MorphToMany} declared with `identifiedBy()` over the rows it reads its members
 * from. Each link renders its readable fields as `meta.pivot` on the member's linkage
 * identifier, and a write sets the writable ones from the same `meta.pivot`.
 *
 * Core carries the declarations and never writes a link row itself. A host branches on
 * this interface rather than on the concrete class to read, validate and write pivot
 * values for either relation.
 */
interface PivotRelationInterface extends RelationInterface
{
    /**
     * The declared pivot fields, in declaration order.
     *
     * @return list<FieldInterface>
     */
    public function pivotFields(): array;

    /**
     * The declared pivot field named `$name`, or `null` when none is declared.
     */
    public function pivotField(string $name): ?FieldInterface;

    /**
     * The pivot fields a link row stores: every field with a column. Declaration order
     * is preserved.
     *
     * @return list<FieldInterface>
     */
    public function storedPivotFields(): array;

    /**
     * The pivot fields with no column, whose values come from their hooks. Declaration
     * order is preserved.
     *
     * @return list<FieldInterface>
     */
    public function computedPivotFields(): array;

    /**
     * The values of the pivot fields read through a hook for one link, keyed by field
     * name, each the field's serialization of a {@see PivotEdge} of `$parent`, `$related`
     * and the link's `$stored` values.
     *
     * @param array<string, mixed> $stored
     *
     * @return array<string, mixed>
     */
    public function hookedPivotValues(mixed $parent, mixed $related, array $stored, JsonApiRequestInterface $request, ?string $outputTimezone = null): array;

    /**
     * The stored pivot fields a write may set from `meta.pivot` in the given context.
     *
     * @return list<FieldInterface>
     */
    public function writablePivotFields(bool $creating): array;

    /**
     * Every pivot field a rendered link may carry under `meta.pivot`, independent of the
     * request: those neither hidden nor write-only.
     *
     * @return list<FieldInterface>
     */
    public function readablePivotFields(): array;

    /**
     * The pivot fields one rendered member carries under `meta.pivot` for this request.
     *
     * @return list<FieldInterface>
     */
    public function readablePivotFieldsFor(JsonApiRequestInterface $request, mixed $relatedMember): array;

    /**
     * The class backing the link rows the relation's builder named with `through()`, or
     * `null` when it named none.
     */
    public function pivotThrough(): ?string;
}
