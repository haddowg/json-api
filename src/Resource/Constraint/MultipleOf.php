<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Constraint;

/**
 * Value must be a multiple of this number (JSON Schema \`multipleOf\`).
 */
final readonly class MultipleOf implements \haddowg\JsonApi\Resource\Constraint\ConstraintInterface
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
