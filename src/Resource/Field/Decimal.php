<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Resource\Constraint\ExclusiveMax;
use haddowg\JsonApi\Resource\Constraint\ExclusiveMin;
use haddowg\JsonApi\Resource\Constraint\In;
use haddowg\JsonApi\Resource\Constraint\Max;
use haddowg\JsonApi\Resource\Constraint\Min;
use haddowg\JsonApi\Resource\Constraint\MultipleOf;

/**
 * A floating-point attribute (JSON `type: number`). Serializes/hydrates as
 * `float`.
 */
final class Decimal extends AbstractField
{
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
     * @param list<int|float> $values
     * @return static
     */
    public function in(array $values): static
    {
        return $this->addConstraint(new In($values, $this->currentContext()));
    }

    protected function serializeValue(mixed $raw): mixed
    {
        return $raw === null ? null : (\is_numeric($raw) ? (float) $raw : $raw);
    }

    protected function deserializeValue(mixed $value): mixed
    {
        return \is_numeric($value) ? (float) $value : $value;
    }
}
