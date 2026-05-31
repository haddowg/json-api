<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Constraint;

/**
 * Exclusive lower bound (JSON Schema \`exclusiveMinimum\`).
 */
final readonly class ExclusiveMin implements Constraint
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
