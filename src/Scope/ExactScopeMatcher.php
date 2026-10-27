<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Scope;

/**
 * A required scope is covered only by a granted scope spelled exactly the same.
 */
final class ExactScopeMatcher implements ScopeMatcherInterface
{
    public function satisfies(array $granted, string $required): bool
    {
        return \in_array($required, $granted, true);
    }
}
