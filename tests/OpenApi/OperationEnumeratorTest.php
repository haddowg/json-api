<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\EnumeratedOperation;
use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\Metadata\RelationMetadataInterface;
use haddowg\JsonApi\OpenApi\OperationEnumerator;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeActionMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\UriFieldNameRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\WriteOnlyRelationMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OperationEnumerator::class)]
#[CoversClass(EnumeratedOperation::class)]
final class OperationEnumeratorTest extends TestCase
{
    #[Test]
    public function aFullTypeListsEveryOperationInDocumentOrder(): void
    {
        $type = $this->articles(
            OperationType::cases(),
            [
                new FakeRelationMetadata('author', ['people'], false),
                new FakeRelationMetadata('tags', ['tags'], true),
            ],
            [new FakeActionMetadata('publish', ['POST', 'GET', 'post'], tags: ['Publishing'])],
        );

        self::assertSame([
            ['fetchCollection', '/articles', 'get', 'fetchCollection.articles'],
            ['create', '/articles', 'post', 'create.articles'],
            ['fetchOne', '/articles/{id}', 'get', 'fetchOne.articles'],
            ['update', '/articles/{id}', 'patch', 'update.articles'],
            ['delete', '/articles/{id}', 'delete', 'delete.articles'],
            ['fetchRelated.author', '/articles/{id}/author', 'get', 'fetchRelated.articles.author'],
            ['fetchRelationship.author', '/articles/{id}/relationships/author', 'get', 'fetchRelationship.articles.author'],
            ['updateRelationship.author', '/articles/{id}/relationships/author', 'patch', 'updateRelationship.articles.author'],
            ['fetchRelated.tags', '/articles/{id}/tags', 'get', 'fetchRelated.articles.tags'],
            ['fetchRelationship.tags', '/articles/{id}/relationships/tags', 'get', 'fetchRelationship.articles.tags'],
            ['updateRelationship.tags', '/articles/{id}/relationships/tags', 'patch', 'updateRelationship.articles.tags'],
            ['addRelationship.tags', '/articles/{id}/relationships/tags', 'post', 'addRelationship.articles.tags'],
            ['removeRelationship.tags', '/articles/{id}/relationships/tags', 'delete', 'removeRelationship.articles.tags'],
            ['action.publish.post', '/articles/{id}/-actions/publish', 'post', 'action.articles.publish.post'],
            ['action.publish.get', '/articles/{id}/-actions/publish', 'get', 'action.articles.publish.get'],
        ], self::summarise(OperationEnumerator::forType($type)));
    }

    #[Test]
    public function crudAndRelationOperationsCarryTheTypeTagsAndAnActionItsOwn(): void
    {
        $operations = OperationEnumerator::forType($this->articles(
            [OperationType::FetchOne],
            [],
            [new FakeActionMetadata('publish', tags: ['Publishing'])],
        ));

        self::assertSame(['Articles'], $operations[0]->tags);
        self::assertSame(['Publishing'], $operations[1]->tags);
    }

    #[Test]
    public function theAllowListGatesCrudAndRelationshipMutations(): void
    {
        $type = $this->articles(
            [OperationType::FetchOne, OperationType::Delete],
            [new FakeRelationMetadata('tags', ['tags'], true)],
        );

        self::assertSame(
            ['fetchOne', 'delete', 'fetchRelated.tags', 'fetchRelationship.tags'],
            \array_column(self::summarise(OperationEnumerator::forType($type)), 0),
        );
    }

    #[Test]
    public function aRelationsFlagsNarrowItsMutations(): void
    {
        $type = $this->articles([OperationType::Update], [
            new FakeRelationMetadata('tags', ['tags'], true, allowsReplace: false, allowsRemove: false),
            new FakeRelationMetadata('author', ['people'], false, relatedEndpoint: false, relationshipEndpoint: false),
        ]);

        self::assertSame(
            ['update', 'fetchRelated.tags', 'fetchRelationship.tags', 'addRelationship.tags'],
            \array_column(self::summarise(OperationEnumerator::forType($type)), 0),
        );
    }

    #[Test]
    public function aWriteOnlyRelationHasNoReads(): void
    {
        $type = $this->articles([OperationType::Update], [
            new WriteOnlyRelationMetadata(new FakeRelationMetadata('secrets', ['secrets'], true)),
        ]);

        self::assertSame(
            ['update', 'updateRelationship.secrets', 'addRelationship.secrets', 'removeRelationship.secrets'],
            \array_column(self::summarise(OperationEnumerator::forType($type)), 0),
        );
    }

    #[Test]
    public function aWriteOnlyRelationOnAReadOnlyTypeHasNoOperations(): void
    {
        $type = $this->articles([OperationType::FetchOne], [
            new WriteOnlyRelationMetadata(new FakeRelationMetadata('secrets', ['secrets'], true)),
        ]);

        self::assertSame(['fetchOne'], \array_column(self::summarise(OperationEnumerator::forType($type)), 0));
    }

    #[Test]
    public function aRelationsPathsUseItsUriFieldName(): void
    {
        $type = $this->articles([], [new UriFieldNameRelationMetadata(new FakeRelationMetadata('coAuthors', ['people'], true), 'co-authors')]);

        $operations = self::summarise(OperationEnumerator::forType($type));

        self::assertSame(['fetchRelated.coAuthors', '/articles/{id}/co-authors', 'get', 'fetchRelated.articles.coAuthors'], $operations[0]);
    }

    #[Test]
    public function onlyCollectionPathsAndCollectionActionsAreNotResourceScoped(): void
    {
        $operations = OperationEnumerator::forType($this->articles(
            OperationType::cases(),
            [new FakeRelationMetadata('tags', ['tags'], true)],
            [
                new FakeActionMetadata('import', scope: ActionScope::Collection),
                new FakeActionMetadata('publish', scope: ActionScope::Resource),
            ],
        ));

        $scoped = [];
        foreach ($operations as $operation) {
            $scoped[$operation->key->name()] = OperationEnumerator::isResourceScoped($operation);
        }

        self::assertSame([
            'fetchCollection' => false,
            'create' => false,
            'fetchOne' => true,
            'update' => true,
            'delete' => true,
            'fetchRelated.tags' => true,
            'fetchRelationship.tags' => true,
            'updateRelationship.tags' => true,
            'addRelationship.tags' => true,
            'removeRelationship.tags' => true,
            'action.import.post' => false,
            'action.publish.post' => true,
        ], $scoped);
    }

    /**
     * @param list<OperationType>             $operations
     * @param list<RelationMetadataInterface> $relations
     * @param list<FakeActionMetadata>        $actions
     */
    private function articles(array $operations, array $relations = [], array $actions = []): FakeTypeMetadata
    {
        return new FakeTypeMetadata(
            type: 'articles',
            uriType: 'articles',
            hasFields: false,
            relations: $relations,
            operations: $operations,
            actions: $actions,
            tags: ['Articles'],
        );
    }

    /**
     * @param list<EnumeratedOperation> $operations
     *
     * @return list<array{string, string, string, string}>
     */
    private static function summarise(array $operations): array
    {
        return \array_map(
            static fn(EnumeratedOperation $operation): array => [$operation->key->name(), $operation->path, $operation->method, $operation->operationId],
            $operations,
        );
    }
}
