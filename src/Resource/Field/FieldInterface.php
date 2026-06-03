<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Constraint\Constraint;

/**
 * One declared member of a {@see \haddowg\JsonApi\Resource\AbstractResource}'s
 * field inventory: an attribute (or, via the {@see Relation} sub-contract, a
 * relationship). A field knows how to **serialize** a value out of a domain
 * object and **hydrate** a value back into it, carries its validation
 * {@see Constraint} metadata, and declares its read-only / sparse-fieldset /
 * sortable behaviour.
 *
 * The fluent builders live on {@see AbstractField}; this interface is the
 * contract the schema base walks.
 */
interface FieldInterface
{
    /**
     * The JSON:API attribute member name (camelCase by convention).
     */
    public function name(): string;

    /**
     * The backing property/column on the domain object. `null` marks a computed
     * field (no underlying column); such a field is serialize-only unless it
     * declares a fill hook.
     */
    public function column(): ?string;

    /**
     * Whether the field is read-only in the given request context, and so must
     * not be hydrated.
     *
     * @param bool $creating true for a create (POST) request, false for update (PATCH)
     */
    public function isReadOnly(bool $creating): bool;

    /**
     * Whether the field is hidden from serialization entirely.
     */
    public function isHidden(): bool;

    /**
     * Whether the field participates in sparse-fieldset filtering. A field that
     * opts out (`notSparseField()`) is always serialized regardless of a
     * `fields[type]` parameter.
     */
    public function isSparseField(): bool;

    /**
     * Whether the field may be used as a sort key.
     */
    public function isSortable(): bool;

    /**
     * The validation constraints declared on this field.
     *
     * @return list<\haddowg\JsonApi\Resource\Constraint\ConstraintInterface>
     */
    public function constraints(): array;

    /**
     * Produces the serialized attribute value for `$model`.
     */
    public function serialize(mixed $model, JsonApiRequestInterface $request, string $name): mixed;

    /**
     * Hydrates `$value` into `$model`, returning the (possibly replaced) domain
     * object.
     *
     * @param array<string, mixed> $data the full resource data array
     */
    public function hydrate(mixed $model, mixed $value, array $data, JsonApiRequestInterface $request): mixed;
}
