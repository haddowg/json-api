<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Constraint;

/**
 * Minimum number of array items (JSON Schema \`minItems\`).
 */
final readonly class MinItems implements Constraint
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
