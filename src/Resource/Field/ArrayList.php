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
    private bool $sorted = false;

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
