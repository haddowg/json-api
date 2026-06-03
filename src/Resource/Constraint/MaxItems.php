<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Constraint;

/**
 * Maximum number of array items (JSON Schema \`maxItems\`).
 */
final readonly class MaxItems implements \haddowg\JsonApi\Resource\Constraint\ConstraintInterface
{
    public function __construct(
        public int $value,
        public Context $context = new Context(),
    ) {}

    public function context(): Context
    {
        return $this->context;
    }
}
