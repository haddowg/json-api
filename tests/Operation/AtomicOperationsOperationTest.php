<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Operation;

use haddowg\JsonApi\Atomic\AtomicExtension;
use haddowg\JsonApi\Atomic\AtomicOperationCode;
use haddowg\JsonApi\Atomic\OperationDescriptor;
use haddowg\JsonApi\Operation\AtomicOperationsOperation;
use haddowg\JsonApi\Operation\OperationContext;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Tests\Double\StubServer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:extensions-and-profiles')]
final class AtomicOperationsOperationTest extends TestCase
{
    #[Test]
    public function exposesItsDescriptorsContextAndQueryParameters(): void
    {
        $descriptors = [
            new OperationDescriptor(AtomicOperationCode::Add, null, '/articles', ['type' => 'articles'], 0),
        ];
        $query = new QueryParameters([], [], [], [], []);
        $context = new OperationContext(new StubServer());

        $operation = new AtomicOperationsOperation($descriptors, $query, $context);

        self::assertSame($descriptors, $operation->descriptors());
        self::assertSame($query, $operation->queryParameters());
        self::assertSame($context, $operation->context());
    }

    #[Test]
    public function carriesAnInertSyntheticTarget(): void
    {
        $operation = new AtomicOperationsOperation(
            [],
            new QueryParameters([], [], [], [], []),
            new OperationContext(new StubServer()),
        );

        $target = $operation->target();

        self::assertSame(AtomicExtension::NAMESPACE, $target->type);
        self::assertFalse($target->hasId());
        self::assertFalse($target->hasRelationship());
    }

    #[Test]
    public function isAJsonApiOperation(): void
    {
        self::assertInstanceOf(
            \haddowg\JsonApi\Operation\JsonApiOperationInterface::class,
            new AtomicOperationsOperation(
                [],
                new QueryParameters([], [], [], [], []),
                new OperationContext(new StubServer()),
            ),
        );
    }

    #[Test]
    public function isReadonly(): void
    {
        self::assertTrue((new \ReflectionClass(AtomicOperationsOperation::class))->isReadOnly());
    }
}
