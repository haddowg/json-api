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
     * The declared pivot association entity (the
     * {@see BelongsToManyBuilder::through()} override), or `null` when none was
     * declared.
     */
    public function pivotThrough(): ?string
    {
        return $this->pivotThrough;
    }
}
