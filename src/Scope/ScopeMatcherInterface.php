<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Scope;

/**
 * Decides whether the scopes a token grants cover one scope an operation requires. Swap
 * it for a scheme with hierarchy or wildcards; core ships only {@see ExactScopeMatcher}.
 */
interface ScopeMatcherInterface
{
    /**
     * @param list<string> $granted
     */
    public function satisfies(array $granted, string $required): bool;
}
