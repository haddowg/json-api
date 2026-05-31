<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Constraint;

/**
 * Maximum number of object properties (JSON Schema \`maxProperties\`).
 */
final readonly class MaxProperties implements Constraint
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
