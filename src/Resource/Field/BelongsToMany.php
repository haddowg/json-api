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
 * `computed()` or `computedUsing()`, it has no column and its value comes from its hook. A
 * computed field is never written, and a relation whose pivot fields are all computed
 * needs no stored pivot at all ({@see storedPivotFields()}). A stored field with an
 * `extractUsing()` hook stays stored and writable and is read through the hook. Either way
 * the hook receives a {@see PivotEdge} rather than a join row ({@see hookedPivotValues()}).
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
     * `computedUsing()`). A host renders it through {@see hookedPivotValues()}, never reads
     * it off a join row, and never writes it. An `extractUsing()` hook on a field with a
     * column changes only how it is read, as on an attribute.
     */
    public static function isComputedPivotField(FieldInterface $field): bool
    {
        return $field->column() === null;
    }

    /**
     * Whether a pivot field's rendered value comes from its hook rather than the stored
     * column: a computed field, or a stored one with an `extractUsing()` hook.
     */
    public static function readsPivotFieldThroughHook(FieldInterface $field): bool
    {
        return self::isComputedPivotField($field)
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
     * The values of the pivot fields read through a hook for one link
     * ({@see readsPivotFieldThroughHook()}), keyed by field name: each field's own
     * serialization of a {@see PivotEdge} of `$parent`, `$related` and the link's `$stored`
     * values, so its hook sees both ends and the stored row and its cast applies as it does
     * to an attribute. `$stored` holds the stored fields' values as the host read them,
     * keyed by field name, and is empty when the link has no join row. `$outputTimezone` is
     * the server's default output zone, which a date field adopts unless it set its own.
     * A host renders these over the stored values; it still renders only what
     * {@see readablePivotFieldsFor()} returns.
     *
     * @param array<string, mixed> $stored
     *
     * @return array<string, mixed>
     */
    public function hookedPivotValues(mixed $parent, mixed $related, array $stored, JsonApiRequestInterface $request, ?string $outputTimezone = null): array
    {
        $edge = new PivotEdge($parent, $related, $stored);
        $values = [];
        foreach ($this->pivotFields as $field) {
            if (!self::readsPivotFieldThroughHook($field)) {
                continue;
            }
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
