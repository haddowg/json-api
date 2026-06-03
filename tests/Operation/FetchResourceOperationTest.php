<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Operation;

use haddowg\JsonApi\Operation\FetchResourceOperation;
use haddowg\JsonApi\Operation\OperationContext;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApi\Tests\Double\StubServer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:fetching-data')]
#[Group('spec:fetching-resources')]
final class FetchResourceOperationTest extends TestCase
{
    #[Test]
    public function exposesItsConstructorArguments(): void
    {
        $target = new Target('articles', '1');
        $query = new QueryParameters([], [], [], [], []);
        $context = new OperationContext(new StubServer());

        $operation = new FetchResourceOperation($target, $query, $context);

        self::assertSame($target, $operation->target());
        self::assertSame($query, $operation->queryParameters());
        self::assertSame($context, $operation->context());
    }

    #[Test]
    public function isReadonly(): void
    {
        self::assertTrue((new \ReflectionClass(FetchResourceOperation::class))->isReadOnly());
    }
}
