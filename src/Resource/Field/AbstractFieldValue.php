<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * The readonly base for a built {@see FieldInterface} value object. It holds the
 * immutable {@see FieldState} snapshot a {@see AbstractFieldBuilder} produced and
 * implements the whole consumption surface off it: the read-only / hidden /
 * sparse / sortable predicates, the OpenAPI description-and-example accessors, and
 * the serialize / hydrate machinery (the `serializeUsing` / `extractUsing` /
 * `deserializeUsing` / `fillUsing` hooks plus the per-type value cast).
 *
 * Concrete value objects are `final readonly` and add only their type-specific
 * behaviour — overriding {@see serializeValue()} / {@see deserializeValue()} for a
 * cast, or extending the constructor for extra state ({@see Id}'s encoder). The
 * fluent authoring surface lives on the mirror {@see AbstractFieldBuilder}; this
 * object exposes none of it.
 */
abstract readonly class AbstractFieldValue implements FieldInterface
{
    public function __construct(
        protected FieldState $state,
    ) {}

    public function name(): string
    {
        return $this->state->name;
    }

    public function column(): ?string
    {
        return $this->state->column;
    }

    public function relatedVia(): ?string
    {
        return $this->state->relatedVia;
    }

    public function isReadOnly(bool $creating): bool
    {
        return $creating ? $this->state->readOnlyOnCreate : $this->state->readOnlyOnUpdate;
    }

    public function isReadOnlyFor(bool $creating, JsonApiRequestInterface $request): bool
    {
        if ($this->isReadOnly($creating)) {
            return true;
        }

        $predicate = $creating ? $this->state->readOnlyOnCreateWhen : $this->state->readOnlyOnUpdateWhen;

        return $predicate !== null && $predicate($request);
    }

    public function isWriteOnly(): bool
    {
        return $this->state->writeOnly;
    }

    public function isWriteOnlyFor(JsonApiRequestInterface $request): bool
    {
        return $this->state->writeOnly
            || ($this->state->writeOnlyWhen !== null && ($this->state->writeOnlyWhen)($request));
    }

    public function isHidden(): bool
    {
        return $this->state->hidden;
    }

    public function isHiddenFor(JsonApiRequestInterface $request, mixed $model): bool
    {
        return $this->state->hidden
            || ($this->state->hiddenWhen !== null && ($this->state->hiddenWhen)($model, $request));
    }

    public function hasConditionalReadVisibility(): bool
    {
        // Unconditionally hidden / write-only fields are absent from reads entirely and
        // never reach the read-schema projection; among read-visible fields, presence is
        // request-conditional exactly when a hidden(when:) or writeOnly(when:) predicate
        // is declared.
        return !$this->state->hidden
            && !$this->state->writeOnly
            && ($this->state->hiddenWhen !== null || $this->state->writeOnlyWhen !== null);
    }

    public function isSparseField(): bool
    {
        return $this->state->sparseField;
    }

    public function isSparseByDefault(): bool
    {
        return $this->state->sparseByDefault;
    }

    public function isSortable(): bool
    {
        return $this->state->sortable;
    }

    public function getDescription(): ?string
    {
        return $this->state->description;
    }

    public function hasExample(): bool
    {
        return $this->state->hasExample;
    }

    public function getExample(): mixed
    {
        return $this->state->example;
    }

    public function constraints(): array
    {
        return $this->state->constraints;
    }

    /**
     * What a read can promise about this field's rendered value, for the OpenAPI read
     * schema. `true` means it is never `null`: the author declared `guaranteed()`, or the
     * `serializeUsing()` / `extractUsing()` hook that produces it declares a return type
     * without `null`. `false` means it may be `null` whatever was written: it is
     * flattened through `on()` (any hop may be null), a hook without such a return type
     * produces it, or a request-aware `readOnlyOnCreate()` lets a create skip it. `null`
     * means it renders the written value, so the field's write constraints decide.
     *
     * @internal
     */
    public function readValueGuarantee(): ?bool
    {
        if ($this->state->guaranteed) {
            return true;
        }
        if ($this->state->relatedVia !== null) {
            return false;
        }

        $hook = $this->state->serializeUsing ?? $this->state->extractUsing;
        if ($hook !== null) {
            $type = (new \ReflectionFunction($hook))->getReturnType();

            return $type !== null
                && !$type->allowsNull()
                && !($type instanceof \ReflectionNamedType && \in_array($type->getName(), ['void', 'never'], true));
        }

        return $this->state->readOnlyOnCreateWhen !== null ? false : null;
    }

    /**
     * Whether a `serializeUsing()` or `extractUsing()` hook produces the rendered value,
     * so its shape is the author's rather than the one the field declares.
     *
     * @internal
     */
    public function rendersThroughHook(): bool
    {
        return $this->state->serializeUsing !== null || $this->state->extractUsing !== null;
    }

    public function serialize(mixed $model, JsonApiRequestInterface $request, string $name): mixed
    {
        if ($this->state->serializeUsing !== null) {
            return ($this->state->serializeUsing)($model, $request, $name);
        }

        if ($this->state->extractUsing !== null) {
            return ($this->state->extractUsing)($model, $request, $name);
        }

        $raw = Accessor::get($model, $this->state->column ?? $name);

        return $this->serializeValue($raw);
    }

    /**
     * Serializes the field's value from the domain object alone, without a
     * request. Used for request-independent members such as the resource `id`:
     * an identity must not vary by request, so only the backing column and the
     * value cast are consulted — the request-aware {@see \haddowg\JsonApi\Resource\Field\AbstractFieldBuilder::serializeUsing()} /
     * `extractUsing()` hooks are not.
     *
     * @internal
     */
    public function serializeWithoutRequest(mixed $model): mixed
    {
        $raw = Accessor::get($model, $this->state->column ?? $this->state->name);

        return $this->serializeValue($raw);
    }

    public function hydrate(mixed $model, mixed $value, array $data, JsonApiRequestInterface $request, bool $creating): mixed
    {
        if ($this->state->fillUsing !== null) {
            $result = ($this->state->fillUsing)($model, $value, $data, $this->state->name);

            return $result ?? $model;
        }

        $column = $this->state->column;
        if ($column === null) {
            return $model;
        }

        $value = $this->state->deserializeUsing !== null
            ? ($this->state->deserializeUsing)($value, $data)
            : $this->deserializeValue($value);

        return Accessor::set($model, $column, $value);
    }

    public function castWireValue(mixed $value): mixed
    {
        return $this->deserializeValue($value);
    }

    /**
     * Casts a raw domain value to its serialized representation. Override in
     * concrete value objects (e.g. format a `DateTimeInterface`). Default: identity.
     */
    protected function serializeValue(mixed $raw): mixed
    {
        return $raw;
    }

    /**
     * Casts an incoming JSON value to its domain representation. Override in
     * concrete value objects (e.g. parse a date string). Default: identity.
     */
    protected function deserializeValue(mixed $value): mixed
    {
        return $value;
    }
}
