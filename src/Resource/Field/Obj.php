<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

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
 * Top-level constraints are limited to presence (`required()` / `nullable()`);
 * structural constraints belong on the child fields. A partial `PATCH` merges
 * per-child (an un-supplied child keeps its stored value); an explicit `null` clears
 * the whole object.
 */
final readonly class Obj extends AbstractFieldValue implements ProvidesFieldSchema, AcceptsDefaultOutputTimezoneInterface
{
    /**
     * @param list<FieldInterface> $children
     */
    public function __construct(
        FieldState $state,
        private array $children = [],
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

        return $children === $this->children ? $this : new self($this->state, $children);
    }

    public function serialize(mixed $model, JsonApiRequestInterface $request, string $name): mixed
    {
        if ($this->state->serializeUsing !== null) {
            return ($this->state->serializeUsing)($model, $request, $name);
        }

        if ($this->state->extractUsing !== null) {
            return ($this->state->extractUsing)($model, $request, $name);
        }

        $value = Accessor::get($model, $this->state->column ?? $name);
        if ($value === null) {
            return null;
        }

        // Children address keys *inside* the single object value, so the nested value
        // — not the domain object — is what each child serializes against.
        $nested = [];
        foreach ($this->children as $child) {
            if ($child->isWriteOnly()) {
                continue;
            }

            $nested[$child->name()] = $child->serialize($value, $request, $child->name());
        }

        return JsonObject::of($nested);
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

        $nested = JsonObject::hydrateChildren($this->name(), function () use ($nested, $value, $data, $request, $creating): mixed {
            foreach ($this->children as $child) {
                if ($child->isReadOnly($creating)) {
                    continue;
                }

                if (\array_key_exists($child->name(), $value)) {
                    $nested = $child->hydrate($nested, $value[$child->name()], $data, $request, $creating);
                }
            }

            return $nested;
        });

        return Accessor::set($model, $column, $nested);
    }

    protected function deserializeValue(mixed $value): mixed
    {
        JsonObject::assertNotList($value, $this->name());

        return $value;
    }

    public function projectFieldSchema(SchemaProjector $projector, bool $creating, ?EnumComponentCollector $collector): Schema
    {
        $properties = [];
        $required = [];
        foreach ($this->children as $child) {
            if ($child->isHidden()) {
                continue;
            }

            $properties[$child->name()] = $projector->projectField($child, $creating, $collector);
            // Mirror the top-level attributes projection: a required child populates
            // the object's `required` only in the create context (on update an absent
            // member means "no change").
            if ($creating && $projector->isRequired($child, $creating)) {
                $required[] = $child->name();
            }
        }

        return Schema::ofType('object')
            ->withProperties($properties)
            ->withRequired($required);
    }
}
