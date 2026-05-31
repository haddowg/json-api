<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Operation;

use haddowg\JsonApi\Operation\OperationContext;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Operation\RemoveFromRelationshipOperation;
use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use haddowg\JsonApi\Tests\Double\StubServer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:crud')]
final class RemoveFromRelationshipOperationTest extends TestCase
{
    #[Test]
    public function exposesItsConstructorArgumentsIncludingTheBody(): void
    {
        $target = new Target('articles', '1', 'tags', isRelationshipEndpoint: true);
        $query = new QueryParameters([], [], [], [], []);
        $context = new OperationContext(new StubServer());
        $body = StubJsonApiRequest::create();

        $operation = new RemoveFromRelationshipOperation($target, $query, $context, $body);

        self::assertSame($target, $operation->target());
        self::assertSame($query, $operation->queryParameters());
        self::assertSame($context, $operation->context());
        self::assertSame($body, $operation->body());
    }

    #[Test]
    public function isReadonly(): void
    {
        self::assertTrue((new \ReflectionClass(RemoveFromRelationshipOperation::class))->isReadOnly());
    }
}
