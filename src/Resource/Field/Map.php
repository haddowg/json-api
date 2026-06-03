<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * Exposes a nested JSON object in the resource attributes while spreading its
 * values across multiple flat columns on the **same** domain object. Each child
 * field reads/writes its own column; the child's `name()` is the key inside the
 * nested object.
 *
 * Top-level constraints are limited to presence (`required()` / `nullable()`);
 * structural constraints belong on the child fields. `Map::on($relation)`
 * (related-model column spread) is out of scope for core — see the Symfony
 * bundle.
 */
final class Map extends AbstractField
{
    /**
     * @var list<\haddowg\JsonApi\Resource\Field\FieldInterface>
     */
    private array $children = [];

    /**
     * @return static
     */
    public function fields(\haddowg\JsonApi\Resource\Field\FieldInterface ...$children): static
    {
        $this->children = \array_values($children);

        return $this;
    }

    /**
     * @return list<\haddowg\JsonApi\Resource\Field\FieldInterface>
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

        $nested = [];
        foreach ($this->children as $child) {
            $nested[$child->name()] = $child->serialize($model, $request, $child->name());
        }

        return $nested;
    }

    public function hydrate(mixed $model, mixed $value, array $data, JsonApiRequestInterface $request): mixed
    {
        if ($this->fillUsing !== null) {
            $result = ($this->fillUsing)($model, $value, $data, $this->name);

            return $result ?? $model;
        }

        if (!\is_array($value)) {
            return $model;
        }

        foreach ($this->children as $child) {
            if (\array_key_exists($child->name(), $value)) {
                $model = $child->hydrate($model, $value[$child->name()], $data, $request);
            }
        }

        return $model;
    }
}
