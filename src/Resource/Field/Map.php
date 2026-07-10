<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * Exposes a nested JSON object in the resource attributes while spreading its
 * values across multiple flat columns on the **same** domain object — the built,
 * readonly value object the engine walks. Each child field reads/writes its own
 * column; the child's `name()` is the key inside the nested object. Authors declare
 * one with {@see make()}, which returns a mutable {@see MapBuilder}; the resource
 * **builds** it into this value object before use.
 *
 * Top-level constraints are limited to presence (`required()` / `nullable()`);
 * structural constraints belong on the child fields. `Map::on($relation)`
 * (related-model column spread) is out of scope for core — see the Symfony
 * bundle.
 */
final readonly class Map extends AbstractFieldValue
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

    public static function make(string $name): MapBuilder
    {
        return new MapBuilder($name);
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
        if ($this->state->serializeUsing !== null) {
            return ($this->state->serializeUsing)($model, $request, $name);
        }

        $nested = [];
        foreach ($this->children as $child) {
            // Skip a write-only child the same way the resource skips a write-only
            // top-level field: it is accepted on write but never rendered.
            if ($child->isWriteOnly()) {
                continue;
            }

            $nested[$child->name()] = $child->serialize($model, $request, $child->name());
        }

        return $nested;
    }

    public function hydrate(mixed $model, mixed $value, array $data, JsonApiRequestInterface $request, bool $creating): mixed
    {
        if ($this->state->fillUsing !== null) {
            $result = ($this->state->fillUsing)($model, $value, $data, $this->state->name);

            return $result ?? $model;
        }

        if (!\is_array($value)) {
            return $model;
        }

        foreach ($this->children as $child) {
            // Gate read-only children the same way the resource gates top-level
            // fields, so a child the author marked readOnly() can't be written
            // through the nested object.
            if ($child->isReadOnly($creating)) {
                continue;
            }

            if (\array_key_exists($child->name(), $value)) {
                $model = $child->hydrate($model, $value[$child->name()], $data, $request, $creating);
            }
        }

        return $model;
    }
}
