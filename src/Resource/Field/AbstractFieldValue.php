<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Exception\AttributeValueInvalid;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Constraint\ConstraintInterface;
use haddowg\JsonApi\Resource\Constraint\In;

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
abstract readonly class AbstractFieldValue implements FieldInterface, \haddowg\JsonApi\Resource\Deprecatable
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

    public function isDeprecated(): bool
    {
        return $this->state->deprecated;
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
     * Whether a `serializeUsing()` or `extractUsing()` hook supplies the read value. Only
     * a `serializeUsing()` one owns its shape ({@see rendersThroughSerializeHook()}).
     *
     * @internal
     */
    public function rendersThroughHook(): bool
    {
        return $this->state->serializeUsing !== null || $this->state->extractUsing !== null;
    }

    /**
     * Whether an `extractUsing()` / `computedUsing()` hook supplies the read value instead
     * of the backing column.
     *
     * @internal
     */
    public function extractsThroughHook(): bool
    {
        return $this->state->extractUsing !== null;
    }

    /**
     * Whether a `serializeUsing()` hook produces the rendered value outright. An
     * `extractUsing()` / `computedUsing()` hook only supplies the value a composite's
     * children then render, so it does not count.
     *
     * @internal
     */
    public function rendersThroughSerializeHook(): bool
    {
        return $this->state->serializeUsing !== null;
    }

    /**
     * Whether a `fillUsing()` or `deserializeUsing()` hook takes over hydration.
     *
     * @internal
     */
    public function hydratesThroughHook(): bool
    {
        return $this->state->fillUsing !== null || $this->state->deserializeUsing !== null;
    }

    public function serialize(mixed $model, JsonApiRequestInterface $request, string $name): mixed
    {
        if ($this->state->serializeUsing !== null) {
            return ($this->state->serializeUsing)($model, $request, $name);
        }

        if ($this->state->extractUsing !== null) {
            return $this->serializeComputedValue($this->backingValue(($this->state->extractUsing)($model, $request, $name)));
        }

        $raw = Accessor::get($model, $this->state->column ?? $name);

        return $this->serializeValue($this->backingValue($raw));
    }

    /**
     * The cast applied to an `extractUsing()` / `computedUsing()` value: the field's own
     * {@see serializeValue()}. Each cast passes a value already in wire form through
     * unchanged, so a closure may return either the domain value or its wire form; a
     * field whose cast is not idempotent on its wire form overrides this.
     */
    protected function serializeComputedValue(mixed $value): mixed
    {
        return $this->serializeValue($value);
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

        return $this->serializeValue($this->backingValue($raw));
    }

    public function hydrate(mixed $model, mixed $value, array $data, JsonApiRequestInterface $request, bool $creating): mixed
    {
        return $this->hydrateValue($model, $value, $data, false);
    }

    /**
     * Hydrates the value into a nested value: a key of an {@see Obj}, a {@see OneOf}
     * variant or a structured {@see ArrayList} element, which a data layer keeps in a
     * JSON column. A value declared with `enum()` stays its backing scalar here rather
     * than becoming the case, as a nested {@see DateTime} stays a string.
     *
     * @internal
     *
     * @param array<string, mixed> $data
     */
    public function hydrateNested(mixed $nested, mixed $value, array $data, JsonApiRequestInterface $request, bool $creating): mixed
    {
        return $this->hydrateValue($nested, $value, $data, true);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function hydrateValue(mixed $model, mixed $value, array $data, bool $nested): mixed
    {
        if ($this->state->fillUsing !== null) {
            $result = ($this->state->fillUsing)($model, $nested ? $value : $this->toEnumCase($value), $data, $this->state->name);

            return $result ?? $model;
        }

        $column = $this->state->column;
        if ($column === null) {
            return $model;
        }

        $value = match (true) {
            $this->state->deserializeUsing !== null => ($this->state->deserializeUsing)($value, $data),
            $nested => $this->deserializeValue($value),
            default => $this->toEnumCase($this->deserializeValue($value)),
        };

        return Accessor::set($model, $column, $value);
    }

    public function castWireValue(mixed $value): mixed
    {
        return $this->toEnumCase($this->deserializeValue($value));
    }

    /**
     * The type's value cast alone, leaving a value declared with `enum()` as its backing
     * scalar: the form a value nested in a JSON column keeps.
     *
     * @internal
     */
    public function castNestedWireValue(mixed $value): mixed
    {
        return $this->deserializeValue($value);
    }

    /**
     * Converts a written backing value to the case of the backed enum the field declares
     * with `enum()`, or `in()` over that enum's cases. A field declaring none, `null`, a
     * case already, or a value of the wrong scalar type comes back unchanged, the last for
     * the adapter's validator to refuse.
     *
     * @throws AttributeValueInvalid when the value is not one of the enum's backing values
     */
    protected function toEnumCase(mixed $value): mixed
    {
        $enum = $this->declaredEnum();

        return $enum === null ? $value : $this->caseOf($enum, $value, $this->state->name);
    }

    /**
     * @param class-string<\BackedEnum> $enum
     *
     * @throws AttributeValueInvalid
     */
    protected function caseOf(string $enum, mixed $value, string $pointer): mixed
    {
        // A Decimal field reads a whole number as a float, which an int-backed enum takes
        // as its int.
        $intBacked = (string) (new \ReflectionEnum($enum))->getBackingType() === 'int';
        $backing = match (true) {
            $intBacked && \is_int($value), !$intBacked && \is_string($value) => $value,
            $intBacked && \is_float($value) && \floor($value) === $value => (int) $value,
            default => null,
        };
        if ($backing === null) {
            return $value;
        }

        return $enum::tryFrom($backing)
            ?? throw new AttributeValueInvalid($pointer, \sprintf('%s is not a value of %s', \json_encode($backing, \JSON_THROW_ON_ERROR), $enum));
    }

    /**
     * The backed enum a direct {@see In} among `$constraints` was sourced from, or `null`.
     *
     * @param list<ConstraintInterface> $constraints
     *
     * @return class-string<\BackedEnum>|null
     */
    protected static function enumClassIn(array $constraints): ?string
    {
        foreach ($constraints as $constraint) {
            if ($constraint instanceof In && $constraint->enumClass !== null) {
                return $constraint->enumClass;
            }
        }

        return null;
    }

    /**
     * The backed enum this field declares with `enum()`, or `in()` over that enum's
     * cases, or `null`.
     *
     * @return class-string<\BackedEnum>|null
     */
    protected function declaredEnum(): ?string
    {
        return self::enumClassIn($this->state->constraints);
    }

    /**
     * A case of the declared enum reads as its backing value, so a property typed with
     * the enum renders as one holding the scalar does.
     */
    protected function backingValue(mixed $raw): mixed
    {
        return $raw instanceof \BackedEnum && $this->declaredEnum() !== null ? $raw->value : $raw;
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
