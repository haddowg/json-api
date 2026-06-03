<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Constraint;

/**
 * Maximum string length (JSON Schema \`maxLength\`).
 */
final readonly class MaxLength implements \haddowg\JsonApi\Resource\Constraint\ConstraintInterface
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
