<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Constraint;

/**
 * String must be a valid email address (JSON Schema \`format: email\`).
 */
final readonly class EmailFormat implements Constraint
{
    public function __construct(
        public Context $context = new Context(),
    ) {}

    public function context(): Context
    {
        return $this->context;
    }
}
