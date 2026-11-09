<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

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
 * `computed()`, `computedUsing()` or `extractUsing()`, its value comes from its hook, which
 * a host calls with a {@see PivotEdge} of the parent and the related member rather than a
 * join row. A computed field is never written, and a relation whose pivot fields are all
 * computed needs no stored pivot at all ({@see storedPivotFields()}).
 */
final readonly class BelongsToMany extends HasMany
{
    /**
     * @param array<string, FieldInterface> $pivotFields the declared pivot fields, keyed by field name (declaration order preserved)
     */
    public function __construct(
        FieldState $state,
        RelationState $relationState,
        private array $pivotFields = [],
        private ?string $pivotThrough = null,
    ) {
        parent::__construct($state, $relationState);
    }

    public static function make(string $name, string $type): BelongsToManyBuilder
    {
        return BelongsToManyBuilder::make($name, $type);
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
     * Whether a pivot field is **computed**: it has no backing column (`computed()`,
     * `computedUsing()`) or an `extractUsing()` hook supplies its value. A host renders it
     * by calling the field's serializer with a {@see PivotEdge}, never reads it off a join
     * row, and never writes it.
     */
    public static function isComputedPivotField(FieldInterface $field): bool
    {
        return $field->column() === null
            || ($field instanceof AbstractFieldValue && $field->extractsThroughHook());
    }

    /**
     * The pivot fields a join row stores: every declared field that is not
     * {@see isComputedPivotField() computed}. When this is empty the relation needs no
     * association entity or pivot model behind it. Declaration order is preserved.
     *
     * @return list<FieldInterface>
     */
    public function storedPivotFields(): array
    {
        return \array_values(\array_filter(
            $this->pivotFields,
            static fn(FieldInterface $field): bool => !self::isComputedPivotField($field),
        ));
    }

    /**
     * The {@see isComputedPivotField() computed} pivot fields. Declaration order is
     * preserved.
     *
     * @return list<FieldInterface>
     */
    public function computedPivotFields(): array
    {
        return \array_values(\array_filter(
            $this->pivotFields,
            self::isComputedPivotField(...),
        ));
    }

    /**
     * The values of the computed pivot fields for one link, keyed by field name: each
     * field's own serialization of a {@see PivotEdge} of `$parent` and `$related`, so its
     * hook receives both ends and its cast applies as it does to an attribute.
     * `$outputTimezone` is the server's default output zone, which a date field adopts
     * unless it set its own. Every computed field is evaluated; a host still renders only
     * what {@see readablePivotFieldsFor()} returns.
     *
     * @return array<string, mixed>
     */
    public function computedPivotValues(mixed $parent, mixed $related, JsonApiRequestInterface $request, ?string $outputTimezone = null): array
    {
        $edge = new PivotEdge($parent, $related);
        $values = [];
        foreach ($this->computedPivotFields() as $field) {
            if ($outputTimezone !== null && $field instanceof AcceptsDefaultOutputTimezoneInterface) {
                $field = $field->withDefaultOutputTimezone($outputTimezone);
            }
            $values[$field->name()] = $field->serialize($edge, $request, $field->name());
        }

        return $values;
    }

    /**
     * The pivot fields **writable** in the given operation context — those not
     * read-only there ({@see FieldInterface::isReadOnly()} resolved by create vs
     * update) and not {@see isComputedPivotField() computed}. These are the fields a host
     * may set from the linkage `meta`; any other field is never written from `meta` (it
     * takes its server-owned or computed value). Declaration order is preserved.
     *
     * @param bool $creating true for a create (POST) request, false for update (PATCH)
     * @return list<FieldInterface>
     */
    public function writablePivotFields(bool $creating): array
    {
        return \array_values(\array_filter(
            $this->pivotFields,
            static fn(FieldInterface $field): bool => $field->isReadOnly($creating) === false && !self::isComputedPivotField($field),
        ));
    }

    /**
     * The pivot fields a rendered linkage member carries under `meta.pivot`: every
     * field that is neither hidden nor write-only. A host renders each of them on every
     * member, `null` when the join row holds none, which is what the projected response
     * pivot schema promises. Declaration order is preserved.
     *
     * This is the request-independent superset the OpenAPI projection documents. A
     * host rendering a member calls {@see readablePivotFieldsFor()} instead.
     *
     * @return list<FieldInterface>
     */
    public function readablePivotFields(): array
    {
        return \array_values(\array_filter(
            $this->pivotFields,
            static fn(FieldInterface $field): bool => !$field->isHidden() && !$field->isWriteOnly(),
        ));
    }

    /**
     * The pivot fields one rendered member carries under `meta.pivot` for this request:
     * every field that is neither hidden ({@see FieldInterface::isHiddenFor()}) nor
     * write-only ({@see FieldInterface::isWriteOnlyFor()}). A pivot field's
     * `hidden(when:)` predicate receives `$relatedMember`, the far-side resource whose
     * linkage or resource object carries the `meta.pivot`, never the join row. Call it
     * once per rendered member. Declaration order is preserved.
     *
     * @return list<FieldInterface>
     */
    public function readablePivotFieldsFor(JsonApiRequestInterface $request, mixed $relatedMember): array
    {
        return \array_values(\array_filter(
            $this->pivotFields,
            static fn(FieldInterface $field): bool => !$field->isWriteOnlyFor($request)
                && !$field->isHiddenFor($request, $relatedMember),
        ));
    }

    /**
     * The declared pivot association entity (the
     * {@see BelongsToManyBuilder::through()} override), or `null` when none was
     * declared.
     */
    public function pivotThrough(): ?string
    {
        return $this->pivotThrough;
    }
}
