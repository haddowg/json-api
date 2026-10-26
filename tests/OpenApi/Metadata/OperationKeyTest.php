<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi\Metadata;

use haddowg\JsonApi\OpenApi\Metadata\ActionDeclarations;
use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApi\OpenApi\Metadata\OperationSafety;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeActionMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OperationKey::class)]
#[CoversClass(OperationKind::class)]
#[CoversClass(OperationSafety::class)]
final class OperationKeyTest extends TestCase
{
    /**
     * @return iterable<string, array{string, OperationKind, ?string, string}>
     */
    public static function names(): iterable
    {
        yield 'fetchCollection' => ['fetchCollection', OperationKind::FetchCollection, null, 'GET'];
        yield 'fetchOne' => ['fetchOne', OperationKind::FetchOne, null, 'GET'];
        yield 'create' => ['create', OperationKind::Create, null, 'POST'];
        yield 'update' => ['update', OperationKind::Update, null, 'PATCH'];
        yield 'delete' => ['delete', OperationKind::Delete, null, 'DELETE'];
        yield 'fetchRelated' => ['fetchRelated.author', OperationKind::FetchRelated, 'author', 'GET'];
        yield 'fetchRelationship' => ['fetchRelationship.author', OperationKind::FetchRelationship, 'author', 'GET'];
        yield 'updateRelationship' => ['updateRelationship.tags', OperationKind::UpdateRelationship, 'tags', 'PATCH'];
        yield 'addRelationship' => ['addRelationship.tags', OperationKind::AddRelationship, 'tags', 'POST'];
        yield 'removeRelationship' => ['removeRelationship.tags', OperationKind::RemoveRelationship, 'tags', 'DELETE'];
    }

    #[Test]
    #[DataProvider('names')]
    public function aNameParsesAndPrintsBack(string $name, OperationKind $kind, ?string $relation, string $method): void
    {
        $key = OperationKey::fromName($name);

        self::assertSame($kind, $key->kind);
        self::assertSame($relation, $key->relation);
        self::assertSame($method, $key->method);
        self::assertNull($key->safety);
        self::assertSame($name, $key->name());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notNames(): iterable
    {
        yield 'empty' => [''];
        yield 'unknown word' => ['list'];
        yield 'wrong case' => ['FetchOne'];
        yield 'crud with a relation' => ['fetchOne.author'];
        yield 'relation kind without a relation' => ['fetchRelated'];
        yield 'empty relation' => ['fetchRelated.'];
        yield 'action' => ['action.publish.post'];
        yield 'atomic' => ['atomic'];
    }

    #[Test]
    #[DataProvider('notNames')]
    public function anythingElseIsNotAName(string $name): void
    {
        self::assertNull(OperationKey::tryFromName($name));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"' . $name . '" is not an operation name. Use one of fetchCollection, fetchOne, create, update, delete, or one of fetchRelated, fetchRelationship, updateRelationship, addRelationship, removeRelationship followed by a dot and the relation name.');

        OperationKey::fromName($name);
    }

    #[Test]
    public function anActionKeyCarriesItsScopeMethodAndDeclaredSafety(): void
    {
        $key = OperationKey::forAction(new FakeActionMetadata('publish', ['post'], ActionScope::Resource, readOnly: false, idempotent: true), 'post');

        self::assertSame(OperationKind::Action, $key->kind);
        self::assertSame('publish', $key->actionPath);
        self::assertSame(ActionScope::Resource, $key->actionScope);
        self::assertSame('POST', $key->method);
        self::assertSame(['readOnly' => false, 'idempotent' => true], $key->safety?->toArray());
        self::assertSame('action.publish.post', $key->name());
    }

    #[Test]
    public function anActionDeclaringNoSafetyCarriesNone(): void
    {
        self::assertNull(OperationKey::forAction(new FakeActionMetadata('publish'), 'POST')->safety);
    }

    #[Test]
    public function theAtomicEndpointIsAPost(): void
    {
        $key = OperationKey::atomic();

        self::assertSame(OperationKind::Atomic, $key->kind);
        self::assertSame('POST', $key->method);
        self::assertSame('atomic', $key->name());
    }

    #[Test]
    public function aCrudKeyIsBuiltFromEitherSpelling(): void
    {
        self::assertEquals(OperationKey::crud(OperationKind::Update), OperationKey::crud(OperationType::Update));
    }

    #[Test]
    public function aCrudKeyRefusesAnotherKind(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        OperationKey::crud(OperationKind::FetchRelated);
    }

    #[Test]
    public function aRelationKeyRefusesAKindWithoutARelation(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        OperationKey::relation(OperationKind::Update, 'author');
    }

    #[Test]
    public function everyCrudKindMapsToItsOperationType(): void
    {
        foreach (OperationType::cases() as $operation) {
            self::assertSame($operation, OperationKind::fromOperationType($operation)->operationType());
        }
    }

    #[Test]
    public function theReservedActionNamesAreTheCrudAndRelationKinds(): void
    {
        $words = [];
        foreach (OperationKind::cases() as $kind) {
            if ($kind->operationType() !== null || $kind->takesRelation()) {
                $words[] = $kind->value;
            }
        }

        self::assertSame(ActionDeclarations::RESERVED_NAMES, $words);
    }
}
