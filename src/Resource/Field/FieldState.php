<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * The immutable snapshot of a field's declared configuration, produced by a
 * {@see AbstractFieldBuilder} at {@see AbstractFieldBuilder::build()} time and
 * carried by the readonly {@see AbstractFieldValue} value object.
 *
 * It exists to keep the builder → value-object handover in one place: the builder
 * accumulates mutable state, snapshots it into this DTO, and the value object
 * reads its {@see FieldInterface} accessors straight off the snapshot. Concrete
 * value objects that carry extra state (an {@see Id}'s encoder, a {@see Map}'s
 * children) add their own promoted readonly properties alongside it. Internal
 * plumbing — never part of the authoring or consumption surface.
 *
 * @internal
 */
final readonly class FieldState
{
    /**
     * @param \Closure(\haddowg\JsonApi\Request\JsonApiRequestInterface): bool|null      $readOnlyOnCreateWhen request predicate gating read-only-on-create
     * @param \Closure(\haddowg\JsonApi\Request\JsonApiRequestInterface): bool|null      $readOnlyOnUpdateWhen request predicate gating read-only-on-update
     * @param \Closure(\haddowg\JsonApi\Request\JsonApiRequestInterface): bool|null      $writeOnlyWhen        request predicate gating write-only
     * @param \Closure(mixed, \haddowg\JsonApi\Request\JsonApiRequestInterface): bool|null $hiddenWhen         model + request predicate gating hidden
     * @param list<\haddowg\JsonApi\Resource\Constraint\ConstraintInterface>             $constraints          declared validation constraints
     * @param \Closure(mixed, \haddowg\JsonApi\Request\JsonApiRequestInterface, string): mixed|null $serializeUsing serialize hook
     * @param \Closure(mixed, \haddowg\JsonApi\Request\JsonApiRequestInterface, string): mixed|null $extractUsing   computed-value hook
     * @param \Closure(mixed, array<string, mixed>): mixed|null                          $deserializeUsing     deserialize hook
     * @param \Closure(mixed, mixed, array<string, mixed>, string): mixed|null           $fillUsing            fill hook
     */
    public function __construct(
        public string $name,
        public ?string $column,
        public ?string $relatedVia = null,
        public bool $readOnlyOnCreate = false,
        public bool $readOnlyOnUpdate = false,
        public bool $writeOnly = false,
        public bool $hidden = false,
        public ?\Closure $readOnlyOnCreateWhen = null,
        public ?\Closure $readOnlyOnUpdateWhen = null,
        public ?\Closure $writeOnlyWhen = null,
        public ?\Closure $hiddenWhen = null,
        public bool $sparseField = true,
        public bool $sparseByDefault = false,
        public bool $sortable = false,
        public array $constraints = [],
        public ?string $description = null,
        public bool $hasExample = false,
        public mixed $example = null,
        public ?\Closure $serializeUsing = null,
        public ?\Closure $extractUsing = null,
        public ?\Closure $deserializeUsing = null,
        public ?\Closure $fillUsing = null,
    ) {}
}
