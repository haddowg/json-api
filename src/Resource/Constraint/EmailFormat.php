<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Constraint;

/**
 * String must be a valid email address (JSON Schema \`format: email\`).
 *
 * `$strict` opts into RFC-compliant validation; it is typed config carried on the
 * constraint itself, which adapters read when executing. The JSON Schema
 * `format: email` is unaffected by it.
 */
final readonly class EmailFormat implements \haddowg\JsonApi\Resource\Constraint\ConstraintInterface
{
    public function __construct(
        public bool $strict = false,
        public Context $context = new Context(),
    ) {}

    public function context(): Context
    {
        return $this->context;
    }
}
