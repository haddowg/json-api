<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Constraint;

/**
 * The value may be explicitly \`null\` (JSON Schema nullable union).
 */
final readonly class Nullable implements Constraint
{
    public function __construct(
        public Context $context = new Context(),
    ) {}

    public function context(): Context
    {
        return $this->context;
    }
}
