<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Resource\Constraint\ExclusiveMax;
use haddowg\JsonApi\Resource\Constraint\ExclusiveMin;
use haddowg\JsonApi\Resource\Constraint\Max;
use haddowg\JsonApi\Resource\Constraint\Min;
use haddowg\JsonApi\Resource\Constraint\MultipleOf;

/**
 * The mutable **field builder** for an integer attribute. Adds the numeric
 * bound / multiple-of helpers on top of the common {@see AbstractFieldBuilder}
 * surface; {@see build()} freezes it into a readonly {@see Integer} value object.
 */
final class IntegerBuilder extends AbstractFieldBuilder
{
    public function build(): Integer
    {
        return new Integer($this->fieldState());
    }

    /**
     * @return static
     */
    public function min(int $value): static
    {
        return $this->addConstraint(new Min($value, $this->currentContext()));
    }

    /**
     * @return static
     */
    public function max(int $value): static
    {
        return $this->addConstraint(new Max($value, $this->currentContext()));
    }

    /**
     * @return static
     */
    public function exclusiveMin(int $value): static
    {
        return $this->addConstraint(new ExclusiveMin($value, $this->currentContext()));
    }

    /**
     * @return static
     */
    public function exclusiveMax(int $value): static
    {
        return $this->addConstraint(new ExclusiveMax($value, $this->currentContext()));
    }

    /**
     * @return static
     */
    public function multipleOf(int $value): static
    {
        return $this->addConstraint(new MultipleOf($value, $this->currentContext()));
    }

    /**
     * Restricts the value to an enumerated set of integers. Members may be plain
     * integers or **int-backed-enum cases** (normalized to their backing value),
     * matching {@see AbstractFieldBuilder::in()}.
     *
     * @param list<int|\BackedEnum> $values
     * @return static
     */
    public function in(array $values): static
    {
        return parent::in($values);
    }
}
