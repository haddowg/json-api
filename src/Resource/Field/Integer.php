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
 * An integer attribute (JSON `type: integer`). Serializes/hydrates as `int`.
 */
final class Integer extends AbstractField
{
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
     * @param list<int> $values
     * @return static
     */
    public function in(array $values): static
    {
        return $this->addConstraint(new In($values, $this->currentContext()));
    }

    protected function serializeValue(mixed $raw): mixed
    {
        return $raw === null ? null : (\is_numeric($raw) ? (int) $raw : $raw);
    }

    protected function deserializeValue(mixed $value): mixed
    {
        return \is_numeric($value) ? (int) $value : $value;
    }
}
