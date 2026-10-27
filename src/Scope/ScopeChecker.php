<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Scope;

use haddowg\JsonApi\Catalog\OperationDefinition;

/**
 * Compares granted scopes with required ones, the way {@see ScopeEnforcer} does, so code
 * outside a request, such as one listing what a token may call, reaches the same answer.
 */
final readonly class ScopeChecker
{
    public function __construct(
        private ScopeMatcherInterface $matcher = new ExactScopeMatcher(),
    ) {}

    /**
     * Whether the grant covers every scope the operation needs. A `null` grant, a request
     * with no scoped token, is never refused for scopes, so it satisfies every operation.
     *
     * @param list<string>|null $granted
     */
    public function satisfies(OperationDefinition $operation, ?array $granted): bool
    {
        return $granted === null || $this->missing($granted, $operation->requiredScopes) === [];
    }

    /**
     * The required scopes the grant does not cover.
     *
     * @param list<string> $granted
     * @param list<string> $required
     *
     * @return list<string>
     */
    public function missing(array $granted, array $required): array
    {
        return \array_values(\array_filter($required, fn(string $scope): bool => !$this->matcher->satisfies($granted, $scope)));
    }
}
