<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Constraint;

/**
 * String must match a regular expression (JSON Schema `pattern`).
 *
 * The pattern is an ECMA-262 regular expression **source** without delimiters,
 * as JSON Schema requires.
 */
final readonly class Pattern implements \haddowg\JsonApi\Resource\Constraint\ConstraintInterface
{
    public function __construct(
        public string $regex,
        public Context $context = new Context(),
    ) {}

    public function context(): Context
    {
        return $this->context;
    }
}
