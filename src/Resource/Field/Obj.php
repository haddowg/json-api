<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Exception\AttributeValueInvalid;
use haddowg\JsonApi\OpenApi\EnumComponentCollector;
use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\OpenApi\SchemaProjector;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\Internal\JsonObject;

/**
 * A typed nested object attribute stored in a **single** backing value (one JSON
 * column / one array property), with declared child fields addressing keys **inside**
 * that value — the built, readonly value object the engine walks. Authors declare
 * one with {@see make()}, which returns a mutable {@see ObjBuilder}; the resource
 * **builds** it into this value object before use.
 *
 * It is the single-value sibling of {@see Map}: `Map` spreads its children across
 * separate flat columns of the domain object and the nested object is only a wire
 * view; `Obj` keeps the whole object as one value and its children read/write keys
 * within it. So `Obj` is the constructive building block for a structured attribute
 * whose natural storage is a single JSON document — with per-child typing,
 * validation, and `/data/attributes/<obj>/<child>` violation pointers — and the
 * variant shape of the discriminated union built on top of it.
 *
 * Top-level constraints are limited to presence (`required()` / `nullable()`) and the
 * member count (`minProperties()` / `maxProperties()`); structural constraints belong
 * on the child fields. A partial `PATCH` merges per-child (an un-supplied child keeps
 * its stored value); an explicit `null` clears the whole object.
 *
 * The object is closed unless it declares {@see additionalProperties()}: an open object
 * also takes members it does not declare, each value read and written through that one
 * field, and kept beside the declared children in the same associative array.
 */
final readonly class Obj extends AbstractFieldValue implements NamedSchemaInterface, ProvidesFieldSchema, AcceptsDefaultOutputTimezoneInterface
{
    /**
     * @param list<FieldInterface> $children
     */
    public function __construct(
        FieldState $state,
        private array $children = [],
        private ?FieldInterface $additionalProperties = null,
    ) {
        parent::__construct($state);
    }

    public static function make(string $name): ObjBuilder
    {
        return new ObjBuilder($name);
    }

    /**
     * @return list<FieldInterface>
     */
    public function children(): array
    {
        return $this->children;
    }

    /**
     * The field every member the object does not declare reads and writes through, or
     * `null` for a closed object. Its own name is never used.
     */
    public function additionalProperties(): ?FieldInterface
    {
        return $this->additionalProperties;
    }

    /**
     * The members of an object value that no declared child takes, by its name or its
     * stored key. An open object reads and writes them through
     * {@see additionalProperties()}; a closed one has none to read or write.
     *
     * @param array<array-key, mixed> $value
     *
     * @return array<string, mixed>
     */
    public function additionalMembersOf(array $value): array
    {
        $taken = [];
        foreach ($this->children as $child) {
            $taken[$child->name()] = true;
            if ($child->column() !== null) {
                $taken[$child->column()] = true;
            }
        }

        $members = [];
        foreach ($value as $key => $member) {
            if (!isset($taken[(string) $key])) {
                $members[(string) $key] = $member;
            }
        }

        return $members;
    }

    /**
     * Passes the server's default output zone on to the children, so a `DateTime`
     * nested here renders in it too.
     */
    public function withDefaultOutputTimezone(string $timezone): static
    {
        $children = \array_map(
            static fn(FieldInterface $child): FieldInterface => $child instanceof AcceptsDefaultOutputTimezoneInterface
                ? $child->withDefaultOutputTimezone($timezone)
                : $child,
            $this->children,
        );
        $additional = $this->additionalProperties instanceof AcceptsDefaultOutputTimezoneInterface
            ? $this->additionalProperties->withDefaultOutputTimezone($timezone)
            : $this->additionalProperties;

        return $children === $this->children && $additional === $this->additionalProperties ? $this : new self($this->state, $children, $additional);
    }

    public function serialize(mixed $model, JsonApiRequestInterface $request, string $name): mixed
    {
        if ($this->state->serializeUsing !== null) {
            return ($this->state->serializeUsing)($model, $request, $name);
        }

        if ($this->state->extractUsing !== null) {
            return $this->serializeComputed(($this->state->extractUsing)($model, $request, $name), $request);
        }

        return $this->serializeStored(Accessor::get($model, $this->state->column ?? $name), $request);
    }

    /**
     * Runs the declared children over a stored object value. {@see ArrayList} renders
     * each element of a structured list through it.
     *
     * @internal
     */
    public function serializeStored(mixed $value, JsonApiRequestInterface $request): mixed
    {
        if ($value === null) {
            return null;
        }

        // Children address keys *inside* the single object value, so the nested value
        // — not the domain object — is what each child serializes against.
        $nested = [];
        foreach ($this->children as $child) {
            if ($this->rendersChild($child, $value, $request)) {
                $nested[$child->name()] = $child->serialize($value, $request, $child->name());
            }
        }

        $field = $this->additionalProperties;
        if ($field !== null && $this->rendersChild($field, $value, $request) && (\is_array($value) || \is_object($value))) {
            foreach ($this->additionalMembersOf(\is_array($value) ? $value : \get_object_vars($value)) as $key => $member) {
                $nested[$key] = self::serializeMember($field, $member, $request);
            }
        }

        return JsonObject::of($nested);
    }

    /**
     * Renders a value an `extractUsing()` / `computedUsing()` hook produced exactly as
     * {@see serializeStored()} renders a stored one: each declared child formats its own
     * key and a key the object does not declare is dropped, unless
     * {@see additionalProperties()} takes it. A {@see OneOf} renders its matched variant
     * through it.
     *
     * @internal
     */
    public function serializeComputed(mixed $value, JsonApiRequestInterface $request): mixed
    {
        if ($value instanceof \JsonSerializable) {
            $value = $value->jsonSerialize();
        }
        if (!\is_array($value) && !\is_object($value)) {
            return $value;
        }

        return $this->serializeStored($value, $request);
    }

    private function rendersChild(FieldInterface $child, mixed $value, JsonApiRequestInterface $request): bool
    {
        return !$child->isWriteOnlyFor($request) && !$child->isHiddenFor($request, $value);
    }

    /**
     * Renders one undeclared member's value through the member field, which reads it
     * from a one-member object under its own key.
     */
    private static function serializeMember(FieldInterface $field, mixed $member, JsonApiRequestInterface $request): mixed
    {
        return $field->serialize([$field->column() ?? $field->name() => $member], $request, $field->name());
    }

    public function hydrate(mixed $model, mixed $value, array $data, JsonApiRequestInterface $request, bool $creating): mixed
    {
        if ($this->state->fillUsing !== null) {
            $result = ($this->state->fillUsing)($model, $value, $data, $this->state->name);

            return $result ?? $model;
        }

        $column = $this->state->column ?? $this->state->name;

        // An explicit null clears the whole object.
        if ($value === null) {
            return Accessor::set($model, $column, null);
        }

        $value = $this->deserializeValue($value);
        if (!\is_array($value)) {
            return $model;
        }

        // Start from the stored object so a partial PATCH preserves un-supplied
        // children (per-child merge — the single-value twin of Map's per-column merge).
        $nested = Accessor::get($model, $column);
        $nested = \is_array($nested) ? $nested : [];

        $nested = JsonObject::hydrateChildren($this->name(), fn(): mixed => $this->hydrateMembersOnto($nested, $value, $data, $request, $creating));

        return Accessor::set($model, $column, $nested);
    }

    /**
     * Builds one element of a structured {@see ArrayList} from its request value,
     * starting from nothing rather than a stored element.
     *
     * @internal
     *
     * @param array<array-key, mixed> $value
     * @param array<string, mixed>    $data
     */
    public function hydrateElement(array $value, array $data, JsonApiRequestInterface $request, bool $creating): mixed
    {
        return $this->hydrateMembersOnto([], $value, $data, $request, $creating);
    }

    /**
     * Hydrates each writable child the request value carries onto `$nested`, then, for
     * an open object, each undeclared member through {@see additionalProperties()},
     * unless that field is read-only for the write.
     *
     * @internal
     *
     * @param array<array-key, mixed> $value
     * @param array<string, mixed>    $data
     */
    public function hydrateMembersOnto(mixed $nested, array $value, array $data, JsonApiRequestInterface $request, bool $creating): mixed
    {
        $nested = self::hydrateChildrenOnto($this->children, $nested, $value, $data, $request, $creating);

        $field = $this->additionalProperties;
        if ($field === null || $field->isHidden() || $field->isReadOnlyFor($creating, $request)) {
            return $nested;
        }

        // The member field writes under its own key, so each value hydrates onto a
        // one-member object and moves to the member's key from there.
        $slot = $field->column() ?? $field->name();
        foreach ($this->additionalMembersOf($value) as $key => $member) {
            try {
                $scratch = $field instanceof DateTime || $field instanceof Union
                    ? $field->hydrateNested([], $member, $data, $request, $creating)
                    : $field->hydrate([], $member, $data, $request, $creating);
            } catch (AttributeValueInvalid $e) {
                $below = \str_starts_with($e->attribute, $field->name()) ? \substr($e->attribute, \strlen($field->name())) : '';

                throw new AttributeValueInvalid(JsonObject::pointerSegment($key) . $below, $e->reason);
            }
            $nested = Accessor::set($nested, $key, Accessor::get($scratch, $slot));
        }

        return $nested;
    }

    /**
     * Hydrates each writable child the request value carries onto `$nested`. A date
     * child, or a union's date member, stores a string here, as
     * {@see DateTime::hydrateNested()} explains.
     *
     * @internal
     *
     * @param list<FieldInterface>    $children
     * @param array<array-key, mixed> $value
     * @param array<string, mixed>    $data
     */
    public static function hydrateChildrenOnto(array $children, mixed $nested, array $value, array $data, JsonApiRequestInterface $request, bool $creating): mixed
    {
        foreach ($children as $child) {
            if ($child->isHidden() || $child->isReadOnlyFor($creating, $request)) {
                continue;
            }

            if (\array_key_exists($child->name(), $value)) {
                $nested = $child instanceof DateTime || $child instanceof Union
                    ? $child->hydrateNested($nested, $value[$child->name()], $data, $request, $creating)
                    : $child->hydrate($nested, $value[$child->name()], $data, $request, $creating);
            }
        }

        return $nested;
    }

    protected function deserializeValue(mixed $value): mixed
    {
        JsonObject::assertNotList($value, $this->name());

        return $value;
    }

    public function projectFieldSchema(SchemaProjector $projector, bool $creating, ?EnumComponentCollector $collector): Schema
    {
        return $projector->projectNestedObject($this, $this->children, $creating, $collector, additionalProperties: $this->additionalProperties);
    }

    public function componentName(): ?string
    {
        return $this->state->component;
    }
}
