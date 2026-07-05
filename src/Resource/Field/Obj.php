<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\OpenApi\EnumComponentCollector;
use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\OpenApi\SchemaProjector;
use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * A typed nested object attribute stored in a **single** backing value (one JSON
 * column / one array property), with declared child fields addressing keys **inside**
 * that value.
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
final class Obj extends AbstractAttribute implements ProvidesFieldSchema
{
    /**
     * @var list<FieldInterface>
     */
    private array $children = [];

    /**
     * @return static
     */
    public function fields(FieldInterface ...$children): static
    {
        $this->children = \array_values($children);

        return $this;
    }

    /**
     * @return list<FieldInterface>
     */
    public function children(): array
    {
        return $this->children;
    }

    public function serialize(mixed $model, JsonApiRequestInterface $request, string $name): mixed
    {
        if ($this->serializeUsing !== null) {
            return ($this->serializeUsing)($model, $request, $name);
        }

        if ($this->extractUsing !== null) {
            return ($this->extractUsing)($model, $request, $name);
        }

        $value = Accessor::get($model, $this->column ?? $name);
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

        return $nested;
    }

    public function hydrate(mixed $model, mixed $value, array $data, JsonApiRequestInterface $request, bool $creating): mixed
    {
        if ($this->fillUsing !== null) {
            $result = ($this->fillUsing)($model, $value, $data, $this->name);

            return $result ?? $model;
        }

        $column = $this->column ?? $this->name;

        // An explicit null clears the whole object.
        if ($value === null) {
            return Accessor::set($model, $column, null);
        }

        if (!\is_array($value)) {
            return $model;
        }

        // Start from the stored object so a partial PATCH preserves un-supplied
        // children (per-child merge — the single-value twin of Map's per-column merge).
        $nested = Accessor::get($model, $column);
        $nested = \is_array($nested) ? $nested : [];

        foreach ($this->children as $child) {
            if ($child->isReadOnly($creating)) {
                continue;
            }

            if (\array_key_exists($child->name(), $value)) {
                $nested = $child->hydrate($nested, $value[$child->name()], $data, $request, $creating);
            }
        }

        return Accessor::set($model, $column, $nested);
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
