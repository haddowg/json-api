<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Scope;

use haddowg\JsonApi\Catalog\OperationDefinition;
use haddowg\JsonApi\Catalog\SecurityLevel;
use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApi\Scope\ScopeChecker;
use haddowg\JsonApi\Scope\ScopeMatcherInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ScopeChecker::class)]
final class ScopeCheckerTest extends TestCase
{
    #[Test]
    public function aNullGrantSatisfiesEveryOperation(): void
    {
        self::assertTrue((new ScopeChecker())->satisfies($this->operation(['articles:read']), null));
    }

    #[Test]
    public function aGrantMustCoverEveryRequiredScope(): void
    {
        $checker = new ScopeChecker();
        $operation = $this->operation(['articles:read', 'people:read']);

        self::assertTrue($checker->satisfies($operation, ['people:read', 'articles:read', 'extra']));
        self::assertFalse($checker->satisfies($operation, ['articles:read']));
        self::assertFalse($checker->satisfies($operation, []));
        self::assertTrue($checker->satisfies($this->operation([]), []));
    }

    #[Test]
    public function itUsesTheConfiguredMatcher(): void
    {
        $wildcard = new class implements ScopeMatcherInterface {
            public function satisfies(array $granted, string $required): bool
            {
                foreach ($granted as $scope) {
                    if ($scope === $required || (\str_ends_with($scope, ':*') && \str_starts_with($required, \substr($scope, 0, -1)))) {
                        return true;
                    }
                }

                return false;
            }
        };

        self::assertTrue((new ScopeChecker($wildcard))->satisfies($this->operation(['articles:read', 'articles:write']), ['articles:*']));
        self::assertSame(['people:read'], (new ScopeChecker($wildcard))->missing(['articles:*'], ['articles:read', 'people:read']));
    }

    /**
     * @param list<string> $scopes
     */
    private function operation(array $scopes): OperationDefinition
    {
        $key = OperationKey::crud(OperationKind::FetchOne)->withType('articles');

        return new OperationDefinition('default', $key, 'fetchOne.articles', 'articles', $key->kind, 'GET', '/articles/{id}', null, null, null, [], null, SecurityLevel::None, $scopes, null);
    }
}
