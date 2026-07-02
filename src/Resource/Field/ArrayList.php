<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Resource\Constraint\Each;
use haddowg\JsonApi\Resource\Constraint\MaxItems;
use haddowg\JsonApi\Resource\Constraint\MinItems;
use haddowg\JsonApi\Resource\Constraint\UniqueItems;

/**
 * A zero-indexed array attribute (JSON `type: array`).
 */
final class ArrayList extends AbstractAttribute
{
    /**
     * The JSON type of each element, surfaced as the OpenAPI `items` type. Defaults
     * to `string` so a list attribute never projects as an untyped `unknown[]`; set
     * another scalar with {@see of()}.
     */
    private string $elementType = 'string';

    private bool $sorted = false;

    /**
     * Declares the JSON type of each list element for the OpenAPI projection (the
     * `items` schema). Accepts a JSON scalar type — `string` (the default),
     * `integer`, `number` or `boolean`. This narrows only the projected schema; it
     * does not cast the serialized value. Any `each()` item constraints compose on
     * top of the declared type.
     *
     * @return static
     */
    public function of(string $elementType): static
    {
        $allowed = ['string', 'integer', 'number', 'boolean'];
        if (!\in_array($elementType, $allowed, true)) {
            throw new \InvalidArgumentException(\sprintf(
                'ArrayList "%s" element type must be one of %s, got "%s".',
                $this->name(),
                \implode(', ', $allowed),
                $elementType,
            ));
        }

        $this->elementType = $elementType;

        return $this;
    }

    /**
     * The declared JSON type of each element (default `string`), read by the OpenAPI
     * projection to type the `items` schema.
     */
    public function elementType(): string
    {
        return $this->elementType;
    }

    /**
     * @return static
     */
    public function minItems(int $count): static
    {
        return $this->addConstraint(new MinItems($count, $this->currentContext()));
    }

    /**
     * @return static
     */
    public function maxItems(int $count): static
    {
        return $this->addConstraint(new MaxItems($count, $this->currentContext()));
    }

    /**
     * @return static
     */
    public function uniqueItems(): static
    {
        return $this->addConstraint(new UniqueItems($this->currentContext()));
    }

    /**
     * Applies the given constraints to every item.
     *
     * @return static
     */
    public function each(\haddowg\JsonApi\Resource\Constraint\ConstraintInterface ...$constraints): static
    {
        return $this->addConstraint(new Each(\array_values($constraints), $this->currentContext()));
    }

    /**
     * Sorts the list on serialization.
     *
     * @return static
     */
    public function sorted(): static
    {
        $this->sorted = true;

        return $this;
    }

    protected function serializeValue(mixed $raw): mixed
    {
        if (!\is_array($raw)) {
            return $raw;
        }

        $list = \array_values($raw);
        if ($this->sorted) {
            \sort($list);
        }

        return $list;
    }
}
