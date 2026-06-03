<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Constraint;

/**
 * Inclusive upper bound (JSON Schema \`maximum\`).
 */
final readonly class Max implements \haddowg\JsonApi\Resource\Constraint\ConstraintInterface
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
