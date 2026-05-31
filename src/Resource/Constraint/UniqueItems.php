<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Constraint;

/**
 * Array items must be unique (JSON Schema \`uniqueItems: true\`).
 */
final readonly class UniqueItems implements Constraint
{
    public function __construct(
        public Context $context = new Context(),
    ) {}

    public function context(): Context
    {
        return $this->context;
    }
}
