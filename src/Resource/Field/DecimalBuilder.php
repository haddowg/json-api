<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Resource\Constraint\ExclusiveMax;
use haddowg\JsonApi\Resource\Constraint\ExclusiveMin;
use haddowg\JsonApi\Resource\Constraint\Max;
use haddowg\JsonApi\Resource\Constraint\Min;
use haddowg\JsonApi\Resource\Constraint\MultipleOf;

/**
 * The mutable **field builder** for a floating-point attribute. Adds the numeric
 * bound / multiple-of helpers on top of the common {@see AbstractFieldBuilder}
 * surface; {@see build()} freezes it into a readonly {@see Decimal} value object.
 *
 * @template-covariant TValue = mixed
 *
 * @extends AbstractFieldBuilder<TValue>
 */
final class DecimalBuilder extends AbstractFieldBuilder
{
    /** @use DeclaresDeserializeHook<TValue> */
    use DeclaresDeserializeHook;
    /** @use DeclaresFillHook<TValue> */
    use DeclaresFillHook;

    public function build(): Decimal
    {
        return new Decimal($this->fieldState());
    }

    /**
     * @return static
     */
    public function min(int|float $value): static
    {
        return $this->addConstraint(new Min($value, $this->currentContext()));
    }

    /**
     * @return static
     */
    public function max(int|float $value): static
    {
        return $this->addConstraint(new Max($value, $this->currentContext()));
    }

    /**
     * @return static
     */
    public function exclusiveMin(int|float $value): static
    {
        return $this->addConstraint(new ExclusiveMin($value, $this->currentContext()));
    }

    /**
     * @return static
     */
    public function exclusiveMax(int|float $value): static
    {
        return $this->addConstraint(new ExclusiveMax($value, $this->currentContext()));
    }

    /**
     * @return static
     */
    public function multipleOf(int|float $value): static
    {
        return $this->addConstraint(new MultipleOf($value, $this->currentContext()));
    }

    /**
     * Restricts the value to an enumerated set of numbers. Members may be plain
     * numbers or **int-backed-enum cases** (normalized to their backing value),
     * matching {@see AbstractFieldBuilder::in()}.
     *
     * @template TIn of int|float|\BackedEnum
     *
     * @param list<TIn> $values
     * @phpstan-self-out (TIn is \BackedEnum ? static<TValue|TIn> : static<TValue>)
     * @return $this
     */
    public function in(array $values): static
    {
        parent::in($values);

        return $this;
    }
}
