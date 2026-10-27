<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Scope;

use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\AtomicOperationsMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresResourceClass;
use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Scope\OperationScopeResolverInterface;
use haddowg\JsonApi\Scope\ScopeTable;
use haddowg\JsonApi\Scope\ScopeTableBuilder;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeActionMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeAtomicOperationsMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\ScopedActionMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\ScopedAtomicOperationsMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\ScopedTypeMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ScopeTableBuilder::class)]
#[CoversClass(ScopeTable::class)]
final class ScopeTableBuilderTest extends TestCase
{
    #[Test]
    public function theResolverIsAskedForEveryOperationAndAlwaysForBothReads(): void
    {
        $table = (new ScopeTableBuilder($this->server([
            $this->type('articles', operations: [OperationType::Create]),
        ]), self::byKind()))->build();

        self::assertSame([
            'create' => ['articles:create'],
            'fetchOne' => ['articles:fetchOne'],
            'fetchCollection' => ['articles:fetchCollection'],
        ], $table->types['articles']);
    }

    #[Test]
    public function withoutAResolverNothingIsRequired(): void
    {
        $table = (new ScopeTableBuilder($this->server([$this->type('articles')])))->build();

        self::assertSame([], $table->for('articles', 'update'));
    }

    #[Test]
    public function anOverrideBeatsTheResolverAndAnEmptyOneMeansNone(): void
    {
        $type = new ScopedTypeMetadata($this->type('articles'), ['fetchOne' => ['read'], 'delete' => []]);

        $table = (new ScopeTableBuilder($this->server([$type]), self::byKind()))->build();

        self::assertSame(['read'], $table->for('articles', 'fetchOne'));
        self::assertSame([], $table->for('articles', 'delete'));
        self::assertSame(['articles:update'], $table->for('articles', 'update'));
    }

    #[Test]
    public function anOverrideForAReadTheTypeDoesNotExposeIsAccepted(): void
    {
        $type = new ScopedTypeMetadata($this->type('articles', operations: [OperationType::FetchOne]), ['fetchCollection' => ['list']]);

        self::assertSame(['list'], (new ScopeTableBuilder($this->server([$type])))->build()->for('articles', 'fetchCollection'));
    }

    #[Test]
    public function anOverrideForAnOperationTheTypeDoesNotHaveFailsTheBuild(): void
    {
        $type = new ScopedTypeMetadata($this->type('articles', operations: [OperationType::FetchOne]), ['delete' => ['x']]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The type "articles" declares scopes for the operation "delete", which it does not have. Name one of: fetchOne, fetchCollection.');

        (new ScopeTableBuilder($this->server([$type])))->build();
    }

    #[Test]
    public function anOverrideKeyOutsideTheGrammarFailsTheBuild(): void
    {
        $type = new ScopedTypeMetadata($this->type('articles'), ['read' => ['x']]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('declares scopes for the operation "read"');

        (new ScopeTableBuilder($this->server([$type])))->build();
    }

    #[Test]
    public function aRelatedReadAlsoNeedsTheReadScopesOfWhatItReturns(): void
    {
        $articles = $this->type('articles', relations: [
            FakeRelationMetadata::toOne('author', ['people']),
            FakeRelationMetadata::toMany('comments', ['comments']),
            FakeRelationMetadata::toOne('subject', ['people', 'comments']),
        ]);

        $table = (new ScopeTableBuilder($this->server([$articles, $this->type('people'), $this->type('comments')]), self::byKind()))->build();

        self::assertSame(['articles:fetchRelated', 'people:fetchOne'], $table->for('articles', 'fetchRelated.author'));
        self::assertSame(['articles:fetchRelationship', 'people:fetchOne'], $table->for('articles', 'fetchRelationship.author'));
        self::assertSame(['articles:fetchRelated', 'comments:fetchCollection'], $table->for('articles', 'fetchRelated.comments'));
        self::assertSame(['articles:fetchRelated', 'people:fetchOne', 'comments:fetchOne'], $table->for('articles', 'fetchRelated.subject'));
        self::assertSame(['articles:updateRelationship'], $table->for('articles', 'updateRelationship.comments'));
    }

    #[Test]
    public function aRelatedTypeTheServerDoesNotDescribeIsAskedForByName(): void
    {
        $articles = $this->type('articles', relations: [
            FakeRelationMetadata::toOne('company', ['companies']),
            FakeRelationMetadata::toMany('offices', ['offices']),
        ]);

        $table = (new ScopeTableBuilder($this->server([$articles]), self::byKind()))->build();

        self::assertSame(['articles:fetchRelated', 'companies:fetchOne'], $table->for('articles', 'fetchRelated.company'));
        self::assertSame(['articles:fetchRelationship', 'offices:fetchCollection'], $table->for('articles', 'fetchRelationship.offices'));
        self::assertNull($table->for('companies', 'fetchOne'));
    }

    #[Test]
    public function anUndescribedTypeTheResolverGivesNoScopesContributesNothing(): void
    {
        $resolver = new RecordingScopeResolver();
        $articles = $this->type('articles', relations: [FakeRelationMetadata::toOne('company', ['companies'])]);

        $table = (new ScopeTableBuilder($this->server([$articles]), $resolver))->build();

        self::assertSame([], $table->for('articles', 'fetchRelated.company'));
        self::assertSame('companies', $resolver->seen['fetchOne@companies']->type);
        self::assertSame(OperationKind::FetchOne, $resolver->seen['fetchOne@companies']->kind);
    }

    #[Test]
    public function theResolverIsToldTheTypeOfEveryKey(): void
    {
        $resolver = new RecordingScopeResolver();

        (new ScopeTableBuilder($this->server([$this->type('articles', operations: [OperationType::Delete])]), $resolver))->build();

        self::assertSame('articles', $resolver->seen['delete@articles']->type);
    }

    #[Test]
    public function aPublicOperationGivenScopesFailsTheBuild(): void
    {
        $type = $this->type('articles', publicOperations: [OperationType::FetchCollection]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The operation "fetchCollection" of type "articles" is public but is given the scopes articles:fetchCollection.');

        (new ScopeTableBuilder($this->server([$type]), self::byKind()))->build();
    }

    #[Test]
    public function aRelationDeclaredPublicGivenScopesFailsTheBuild(): void
    {
        $articles = $this->type('articles', relations: [new FakeRelationMetadata('author', ['people'], false, securityRead: false)]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The operation "fetchRelated.author" of type "articles" is public');

        (new ScopeTableBuilder($this->server([$articles, $this->type('people')]), self::byKind()))->build();
    }

    #[Test]
    public function aPublicRelatedReadReturningAScopedTypeFailsTheBuild(): void
    {
        $articles = new ScopedTypeMetadata(
            $this->type('articles', relations: [new FakeRelationMetadata('author', ['people'], false, relationshipEndpoint: false, securityRead: false)]),
            ['fetchRelated.author' => []],
        );
        $people = new ScopedTypeMetadata($this->type('people'), ['fetchOne' => ['people:read']]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The operation "fetchRelated.author" of type "articles" is public, but the resources of type "people" it returns need the scopes people:read.');

        (new ScopeTableBuilder($this->server([$articles, $people])))->build();
    }

    #[Test]
    public function aPublicRelatedReadReturningAnUnscopedTypeBuilds(): void
    {
        $articles = $this->type('articles', relations: [new FakeRelationMetadata('author', ['people'], false, relationshipEndpoint: false, securityRead: false)]);

        $table = (new ScopeTableBuilder($this->server([$articles, $this->type('people')])))->build();

        self::assertSame([], $table->for('articles', 'fetchRelated.author'));
    }

    #[Test]
    public function aRelationInheritingAPublicParentIsPublic(): void
    {
        $articles = new ScopedTypeMetadata(
            $this->type('articles', relations: [FakeRelationMetadata::toMany('tags', ['tags'])], publicOperations: [OperationType::Update]),
            ['update' => []],
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The operation "updateRelationship.tags" of type "articles" is public');

        (new ScopeTableBuilder($this->server([$articles, $this->type('tags')]), self::byKind()))->build();
    }

    #[Test]
    public function aPublicOperationWithNoScopesBuilds(): void
    {
        $type = new ScopedTypeMetadata(
            $this->type('articles', publicOperations: [OperationType::FetchOne]),
            ['fetchOne' => []],
        );

        self::assertSame([], (new ScopeTableBuilder($this->server([$type]), self::byKind()))->build()->for('articles', 'fetchOne'));
    }

    #[Test]
    public function aResolverThatThrowsIsNamedInTheFailure(): void
    {
        $resolver = new class implements OperationScopeResolverInterface {
            public function requiredScopes(?TypeMetadataInterface $type, OperationKey $operation): array
            {
                throw new \RuntimeException('no policy for this');
            }
        };

        try {
            (new ScopeTableBuilder($this->server([$this->type('articles', operations: [OperationType::Delete])]), $resolver))->build();
            self::fail('The build should fail.');
        } catch (\LogicException $exception) {
            self::assertSame('The scope resolver failed for the operation "delete" of type "articles": no policy for this', $exception->getMessage());
            self::assertInstanceOf(\RuntimeException::class, $exception->getPrevious());
        }
    }

    #[Test]
    public function aScopeAHeaderCannotCarryFailsTheBuild(): void
    {
        $type = new ScopedTypeMetadata($this->type('articles'), ['fetchOne' => ['read articles']]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('requires the scope "read articles", which is not a scope token');

        (new ScopeTableBuilder($this->server([$type])))->build();
    }

    #[Test]
    public function anActionIsKeyedPerMethodAndItsResolverKeyCarriesItsSafety(): void
    {
        $resolver = new RecordingScopeResolver();
        $type = $this->type('articles', operations: [], actions: [
            new FakeActionMetadata('export', ['GET', 'POST'], ActionScope::Collection, readOnly: true),
        ]);

        $table = (new ScopeTableBuilder($this->server([$type]), $resolver))->build();

        self::assertSame(['action.export.get', 'action.export.post', 'fetchOne', 'fetchCollection'], \array_keys($table->types['articles']));
        self::assertSame(['readOnly' => true], $resolver->seen['action.export.get']->safety?->toArray());
        self::assertNull($resolver->seen['fetchOne']->safety);
    }

    #[Test]
    public function anActionOverrideCoversEveryMethod(): void
    {
        $type = $this->type('articles', operations: [], actions: [
            new ScopedActionMetadata(new FakeActionMetadata('export', ['GET', 'POST']), ['export']),
        ]);

        $table = (new ScopeTableBuilder($this->server([$type]), self::byKind()))->build();

        self::assertSame(['export'], $table->for('articles', 'action.export.get'));
        self::assertSame(['export'], $table->for('articles', 'action.export.post'));
    }

    #[Test]
    public function theAtomicEndpointIsResolvedWithNoTypeUnlessOverridden(): void
    {
        $resolved = (new ScopeTableBuilder($this->server([], new FakeAtomicOperationsMetadata()), new RecordingScopeResolver()))->build();
        $overridden = (new ScopeTableBuilder($this->server([], new ScopedAtomicOperationsMetadata(['batch'])), self::byKind()))->build();
        $none = (new ScopeTableBuilder($this->server([]), self::byKind()))->build();

        self::assertSame(['atomic'], $resolved->atomic);
        self::assertSame(['batch'], $overridden->atomic);
        self::assertNull($none->atomic);
    }

    #[Test]
    public function theResolverCanReadTheResourceClass(): void
    {
        $resolver = new class implements OperationScopeResolverInterface {
            public function requiredScopes(?TypeMetadataInterface $type, OperationKey $operation): array
            {
                return $type instanceof DeclaresResourceClass && $type->resourceClass() !== null ? [\strtolower(\str_replace('\\', '.', $type->resourceClass()))] : [];
            }
        };
        $type = new ScopedTypeMetadata($this->type('articles'), resourceClass: \stdClass::class);

        self::assertSame(['stdclass'], (new ScopeTableBuilder($this->server([$type]), $resolver))->build()->for('articles', 'fetchOne'));
    }

    #[Test]
    public function oneOperationResolvesLiveWithTheSameRules(): void
    {
        $articles = $this->type('articles', relations: [FakeRelationMetadata::toOne('author', ['people'])]);
        $builder = new ScopeTableBuilder($this->server([$articles, $this->type('people')]), self::byKind());

        self::assertSame(['articles:fetchRelated', 'people:fetchOne'], $builder->resolve('articles', OperationKey::relation(OperationKind::FetchRelated, 'author')));
        self::assertSame(['articles:action'], $builder->resolve('articles', OperationKey::action('archive', ActionScope::Resource, 'POST')));
    }

    #[Test]
    public function anUndescribedTypesReadsResolveLiveByName(): void
    {
        $builder = new ScopeTableBuilder($this->server([]), self::byKind());

        self::assertSame(['ghosts:fetchOne'], $builder->resolve('ghosts', OperationKey::crud(OperationKind::FetchOne)));
        self::assertSame(['ghosts:fetchCollection'], $builder->resolve('ghosts', OperationKey::crud(OperationKind::FetchCollection)));
    }

    #[Test]
    public function anUndescribedTypesOtherOperationsCannotBeResolvedLive(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('the server describes no such type');

        (new ScopeTableBuilder($this->server([])))->resolve('ghosts', OperationKey::crud(OperationKind::Update));
    }

    #[Test]
    public function aTableRoundTripsThroughAnArray(): void
    {
        $table = (new ScopeTableBuilder($this->server([$this->type('articles')], new FakeAtomicOperationsMetadata()), self::byKind()))->build();

        self::assertEquals($table, ScopeTable::fromArray($table->toArray()));
    }

    #[Test]
    public function aMalformedArrayIsNotATable(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ScopeTable::fromArray(['types' => ['articles' => ['fetchOne' => [1]]], 'atomic' => null]);
    }

    /**
     * Names every scope `<type>:<kind>`, and the atomic endpoint `atomic`.
     */
    private static function byKind(): OperationScopeResolverInterface
    {
        return new class implements OperationScopeResolverInterface {
            public function requiredScopes(?TypeMetadataInterface $type, OperationKey $operation): array
            {
                return $operation->type === null ? ['atomic'] : [$operation->type . ':' . $operation->kind->value];
            }
        };
    }

    /**
     * @param list<TypeMetadataInterface> $types
     */
    private function server(array $types, ?AtomicOperationsMetadataInterface $atomic = null): FakeServerMetadata
    {
        return new FakeServerMetadata(title: 'API', version: '1', types: $types, atomicOperations: $atomic);
    }

    /**
     * @param list<OperationType>|null                                              $operations
     * @param list<\haddowg\JsonApi\OpenApi\Metadata\RelationMetadataInterface>     $relations
     * @param list<\haddowg\JsonApi\OpenApi\Metadata\ActionMetadataInterface>       $actions
     * @param list<OperationType>                                                   $publicOperations
     */
    private function type(string $type, ?array $operations = null, array $relations = [], array $actions = [], array $publicOperations = []): FakeTypeMetadata
    {
        return FakeTypeMetadata::resource(
            type: $type,
            fields: [Id::make()->build()],
            relations: $relations,
            operations: $operations,
            actions: $actions,
            publicOperations: $publicOperations,
        );
    }
}

final class RecordingScopeResolver implements OperationScopeResolverInterface
{
    /** @var array<string, OperationKey> */
    public array $seen = [];

    public function requiredScopes(?TypeMetadataInterface $type, OperationKey $operation): array
    {
        $this->seen[$operation->name()] = $operation;
        $this->seen[$operation->name() . '@' . $operation->type] = $operation;

        return $operation->type === null ? ['atomic'] : [];
    }
}
