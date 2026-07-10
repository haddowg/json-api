<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Resource\Constraint\MaxProperties;
use haddowg\JsonApi\Resource\Constraint\MinProperties;

/**
 * The mutable **field builder** for a JSON object attribute exposed as a PHP
 * associative array. Adds the property-count / sort helpers on top of the common
 * {@see AbstractFieldBuilder} surface; {@see build()} freezes it into a readonly
 * {@see ArrayHash} value object.
 */
final class ArrayHashBuilder extends AbstractFieldBuilder
{
    private bool $sortKeys = false;

    private bool $sortValues = false;

    public function build(): ArrayHash
    {
        return new ArrayHash($this->fieldState(), $this->sortKeys, $this->sortValues);
    }

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
}
