<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Constraint;

/**
 * Exclusive upper bound (JSON Schema \`exclusiveMaximum\`).
 */
final readonly class ExclusiveMax implements Constraint
{
    public function __construct(
        public int|float $value,
        public Context $context = new Context(),
    ) {}

    public function context(): Context
    {
        return $this->context;
    }
}
