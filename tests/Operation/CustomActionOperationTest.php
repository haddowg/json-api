<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Operation;

use haddowg\JsonApi\Operation\CustomActionOperation;
use haddowg\JsonApi\Operation\OperationContext;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use haddowg\JsonApi\Tests\Double\StubServer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:crud')]
final class CustomActionOperationTest extends TestCase
{
    #[Test]
    public function exposesItsConstructorArgumentsIncludingActionMethodAndBody(): void
    {
        $target = new Target('articles', '1');
        $query = new QueryParameters([], [], [], [], []);
        $context = new OperationContext(new StubServer());
        $body = StubJsonApiRequest::create();

        $operation = new CustomActionOperation($target, $query, $context, 'publish', 'POST', $body);

        self::assertSame($target, $operation->target());
        self::assertSame($query, $operation->queryParameters());
        self::assertSame($context, $operation->context());
        self::assertSame('publish', $operation->action());
        self::assertSame('POST', $operation->method());
        self::assertSame($body, $operation->body());
    }

    #[Test]
    public function bodyDefaultsToNullForAnInputLessAction(): void
    {
        $operation = new CustomActionOperation(
            new Target('articles'),
            new QueryParameters([], [], [], [], []),
            new OperationContext(new StubServer()),
            'import',
            'POST',
        );

        self::assertNull($operation->body());
    }

    #[Test]
    public function aCollectionScopeActionCarriesNoId(): void
    {
        $operation = new CustomActionOperation(
            new Target('articles'),
            new QueryParameters([], [], [], [], []),
            new OperationContext(new StubServer()),
            'import',
            'POST',
        );

        self::assertFalse($operation->target()->hasId());
    }

    #[Test]
    public function aResourceScopeActionCarriesItsId(): void
    {
        $operation = new CustomActionOperation(
            new Target('articles', '1'),
            new QueryParameters([], [], [], [], []),
            new OperationContext(new StubServer()),
            'publish',
            'POST',
        );

        self::assertTrue($operation->target()->hasId());
        self::assertSame('1', $operation->target()->id);
    }

    #[Test]
    public function isReadonly(): void
    {
        self::assertTrue((new \ReflectionClass(CustomActionOperation::class))->isReadOnly());
    }

    #[Test]
    public function isAJsonApiOperation(): void
    {
        self::assertInstanceOf(
            \haddowg\JsonApi\Operation\JsonApiOperationInterface::class,
            new CustomActionOperation(
                new Target('articles', '1'),
                new QueryParameters([], [], [], [], []),
                new OperationContext(new StubServer()),
                'publish',
                'POST',
            ),
        );
    }
}
