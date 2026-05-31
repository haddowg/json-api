<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Constraint;

/**
 * Inclusive lower bound (JSON Schema \`minimum\`).
 */
final readonly class Min implements Constraint
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
