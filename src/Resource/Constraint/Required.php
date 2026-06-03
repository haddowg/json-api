<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Constraint;

/**
 * Presence/non-emptiness. On create (POST) the field must be present and non-empty; on update (PATCH) absence means "no change" (partial update), so only an explicitly-supplied empty value fails. Scope to a single context with \`requiredOnCreate()\` / \`requiredOnUpdate()\`.
 */
final readonly class Required implements \haddowg\JsonApi\Resource\Constraint\ConstraintInterface
{
    public function __construct(
        public Context $context = new Context(),
    ) {}

    public function context(): Context
    {
        return $this->context;
    }
}
