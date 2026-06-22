<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Resource\Constraint\MaxProperties;
use haddowg\JsonApi\Resource\Constraint\MinProperties;

/**
 * A JSON object attribute exposed as a PHP associative array (JSON
 * `type: object`).
 */
final class ArrayHash extends AbstractAttribute
{
    private bool $sortKeys = false;

    private bool $sortValues = false;

    /**
     * @return static
     */
    public function minProperties(int $count): static
    {
        return $this->addConstraint(new MinProperties($count, $this->currentContext()));
    }

    /**
     * @return static
     */
    public function maxProperties(int $count): static
    {
        return $this->addConstraint(new MaxProperties($count, $this->currentContext()));
    }

    /**
     * Sorts the object by key on serialization.
     *
     * @return static
     */
    public function sortKeys(): static
    {
        $this->sortKeys = true;

        return $this;
    }

    /**
     * Sorts the object by value on serialization (keys preserved).
     *
     * @return static
     */
    public function sortValues(): static
    {
        $this->sortValues = true;

        return $this;
    }

    protected function serializeValue(mixed $raw): mixed
    {
        if (!\is_array($raw)) {
            return $raw;
        }

        if ($this->sortKeys) {
            \ksort($raw);
        }

        if ($this->sortValues) {
            \asort($raw);
        }

        return $raw;
    }
}
