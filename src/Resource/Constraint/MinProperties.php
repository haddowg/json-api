<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Constraint;

/**
 * Minimum number of object properties (JSON Schema \`minProperties\`).
 */
final readonly class MinProperties implements \haddowg\JsonApi\Resource\Constraint\ConstraintInterface
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
