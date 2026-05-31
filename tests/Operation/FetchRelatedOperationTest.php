<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Operation;

use haddowg\JsonApi\Operation\FetchRelatedOperation;
use haddowg\JsonApi\Operation\OperationContext;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApi\Tests\Double\StubServer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:fetching-data')]
final class FetchRelatedOperationTest extends TestCase
{
    #[Test]
    public function exposesItsConstructorArguments(): void
    {
        $target = new Target('articles', '1', 'author');
        $query = new QueryParameters([], [], [], [], []);
        $context = new OperationContext(new StubServer());

        $operation = new FetchRelatedOperation($target, $query, $context);

        self::assertSame($target, $operation->target());
        self::assertSame($query, $operation->queryParameters());
        self::assertSame($context, $operation->context());
    }

    #[Test]
    public function isReadonly(): void
    {
        self::assertTrue((new \ReflectionClass(FetchRelatedOperation::class))->isReadOnly());
    }
}
