<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Constraint\ConstraintInterface;

/**
 * One declared member of a {@see \haddowg\JsonApi\Resource\AbstractResource}'s
 * field inventory: an attribute (or, via the {@see RelationInterface} sub-contract, a
 * relationship). A field knows how to **serialize** a value out of a domain
 * object and **hydrate** a value back into it, carries its validation
 * {@see ConstraintInterface} metadata, and declares its read-only / sparse-fieldset /
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
     * The `.`-separated chain of declared, to-one relations this attribute is
     * **flattened from** ({@see AbstractField::on()}) — `'author'` (single hop) or
     * `'publisher.country'` (multi-hop) — or `null` for a plain attribute. When
     * non-null, the owning resource walks the chain to its final related object and
     * the field reads its `column() ?? name()` off / writes it onto *that* object,
     * rather than the owning model. The chain participates in the resource's
     * eager-load set ({@see \haddowg\JsonApi\Serializer\DeclaresEagerLoadsInterface}).
     */
    public function relatedVia(): ?string;

    /**
     * Whether the field is **unconditionally** read-only in the given request
     * context, and so must not be hydrated. A field whose read-only state is a
     * request predicate ({@see AbstractField::readOnly()} with a closure) reports
     * `false` here (it is not unconditionally restricted) — the request-aware
     * {@see isReadOnlyFor()} is the gate the hydrate/validate paths consult.
     *
     * @param bool $creating true for a create (POST) request, false for update (PATCH)
     */
    public function isReadOnly(bool $creating): bool;

    /**
     * Whether the field is read-only **for this request** in the given context.
     * Resolves the unconditional flag ({@see isReadOnly()}) OR the read-only
     * predicate (if one was declared) against the request. This is the gate the
     * hydration and validation paths consult; build-time / schema paths use the
     * unconditional {@see isReadOnly()} (which documents the superset).
     *
     * @param bool $creating true for a create (POST) request, false for update (PATCH)
     */
    public function isReadOnlyFor(bool $creating, JsonApiRequestInterface $request): bool;

    /**
     * Whether the field is **unconditionally** write-only — accepted on write
     * (hydrated on both create and update, and still validated) but never
     * serialized into the resource `attributes`, so it appears on no read and a
     * sparse fieldset naming it cannot resurrect it. The inverse of
     * {@see isReadOnly()}. A field whose write-only state is a request predicate
     * reports `false` here; the render path consults {@see isWriteOnlyFor()}.
     */
    public function isWriteOnly(): bool;

    /**
     * Whether the field is write-only **for this request** — the unconditional
     * flag ({@see isWriteOnly()}) OR the write-only predicate (if declared)
     * resolved against the request. This is the gate the render path consults;
     * the OpenAPI/schema paths use the unconditional {@see isWriteOnly()}.
     */
    public function isWriteOnlyFor(JsonApiRequestInterface $request): bool;

    /**
     * Whether the field is **unconditionally** hidden from serialization
     * entirely. A field whose hidden state is a request predicate reports `false`
     * here; the render path consults {@see isHiddenFor()}.
     */
    public function isHidden(): bool;

    /**
     * Whether the field is hidden from serialization **for this request** — the
     * unconditional flag ({@see isHidden()}) OR the hidden predicate (if declared)
     * resolved against the request and the domain `$model`. This is the gate the
     * render path consults; build-time / schema paths use the unconditional
     * {@see isHidden()} (which documents the superset).
     */
    public function isHiddenFor(JsonApiRequestInterface $request, mixed $model): bool;

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
     * The human-readable description surfaced by the OpenAPI generator, or `null`
     * when none was declared.
     */
    public function getDescription(): ?string;

    /**
     * Whether an example value was declared (distinct from a declared `null`
     * example), surfaced by the OpenAPI generator.
     */
    public function hasExample(): bool;

    /**
     * The example value surfaced by the OpenAPI generator; only meaningful when
     * {@see hasExample()} is true.
     */
    public function getExample(): mixed;

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
     * object. `$creating` carries the operation context (true on create, false on
     * update) so a composite field — e.g. {@see \haddowg\JsonApi\Resource\Field\Map} —
     * can gate read-only children the same way the resource gates top-level fields.
     *
     * @param array<string, mixed> $data the full resource data array
     */
    public function hydrate(mixed $model, mixed $value, array $data, JsonApiRequestInterface $request, bool $creating): mixed;
}
