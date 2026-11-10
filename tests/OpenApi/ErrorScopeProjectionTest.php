<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\Exception\ClassListErrorSource;
use haddowg\JsonApi\Exception\ErrorCatalogSourceInterface;
use haddowg\JsonApi\Exception\ErrorScope;
use haddowg\JsonApi\Exception\ScopedErrorSource;
use haddowg\JsonApi\OpenApi\ErrorCatalogProjector;
use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Tests\Exception\Fixture\ArticleLocked;
use haddowg\JsonApi\Tests\Exception\Fixture\PaymentRequired;
use haddowg\JsonApi\Tests\Exception\Fixture\RivalResourceNotFound;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\ContributingServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeActionMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeAtomicOperationsMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A code whose scope names some operations is offered by those operations alone: each answers
 * the code's status with an error document of its own, named for the operation and the status,
 * offering the codes every operation raises plus the scoped ones, and the rest keep the shared
 * response. The scope is the descriptor's own, or one a {@see ScopedErrorSource} gives it.
 */
#[CoversClass(ErrorCatalogProjector::class)]
#[CoversClass(OpenApiProjector::class)]
#[CoversClass(ErrorScope::class)]
#[CoversClass(ScopedErrorSource::class)]
final class ErrorScopeProjectionTest extends TestCase
{
    #[Test]
    public function aDescriptorsScopeOffersItsCodeOnlyOnTheOperationsItNames(): void
    {
        // ArticleLocked scopes itself to the article update and delete.
        $document = $this->project(new ClassListErrorSource(ArticleLocked::class));

        self::assertSame('ArticlesUpdate409', $this->errorDocument($document, '/articles/{id}', 'patch', '409'));
        self::assertSame('ArticlesDelete409', $this->errorDocument($document, '/articles/{id}', 'delete', '409'));
        self::assertSame('#/components/responses/Conflict', $this->at($document, 'paths', '/articles', 'post', 'responses', '409', '$ref'));

        $schemas = $this->arr($document, 'components', 'schemas');
        $shared = $this->arr($schemas, 'ErrorDocument409', 'properties', 'errors', 'items');
        $narrowed = $this->arr($schemas, 'ArticlesUpdate409', 'properties', 'errors', 'items');
        self::assertNotContains(['$ref' => '#/components/schemas/Error409ArticleLocked'], $this->arr($shared, 'anyOf'));
        self::assertSame(
            [...$this->arr($shared, 'anyOf'), ['$ref' => '#/components/schemas/Error409ArticleLocked']],
            $this->arr($narrowed, 'anyOf'),
            'the narrowed document offers the shared codes, then the scoped ones, behind the generic branch',
        );
        self::assertSame('Article locked', $this->at($schemas, 'Error409ArticleLocked', 'title'));
        self::assertArrayHasKey('description', $shared, 'the shared document explains the open list');
        self::assertArrayNotHasKey('description', $narrowed, 'an operation\'s own document does not repeat it');
        self::assertSame($this->at($schemas, 'ArticlesUpdate409'), $this->at($schemas, 'ArticlesDelete409'));
    }

    #[Test]
    public function anOperationsOwnErrorResponseIsInlineAndNoResponseComponentIsEmitted(): void
    {
        $document = $this->project(new ClassListErrorSource(ArticleLocked::class));

        $response = $this->arr($document, 'paths', '/articles/{id}', 'patch', 'responses', '409');
        self::assertArrayNotHasKey('$ref', $response);
        self::assertSame('Conflict — the request conflicts with the resource state (e.g. a type or id mismatch).', $response['description'] ?? null);
        $components = \array_keys($this->arr($document, 'components', 'responses'));
        self::assertContains('Conflict', $components);
        self::assertNotContains('ArticlesUpdate409', $components);
        self::assertSame([], \preg_grep('/^(?!Conflict$).*(Conflict|409)/', $components), 'no response component is emitted beside the operation\'s document');
    }

    #[Test]
    public function anOperationsErrorDocumentKeepsItsNameWhenAScopedCodeJoins(): void
    {
        $one = $this->project(new ClassListErrorSource(ArticleLocked::class));
        $two = $this->project(
            new ClassListErrorSource(ArticleLocked::class),
            new ScopedErrorSource(ErrorScope::on('articles', 'update'), PaymentRequired::class),
        );

        self::assertSame('ArticlesUpdate409', $this->errorDocument($one, '/articles/{id}', 'patch', '409'));
        self::assertSame('ArticlesUpdate409', $this->errorDocument($two, '/articles/{id}', 'patch', '409'));
        self::assertSame('ArticlesUpdate402', $this->errorDocument($two, '/articles/{id}', 'patch', '402'));
    }

    #[Test]
    public function relationshipActionAndAtomicOperationsNameTheirOwnErrorDocuments(): void
    {
        $server = new FakeServerMetadata(title: 'API', version: '1.0.0', types: [
            FakeTypeMetadata::resource(
                type: 'blog-posts',
                fields: [Id::make()->example('1')->build(), Str::make('title')->example('Hello')->build()],
                relations: [FakeRelationMetadata::toOne('author', ['people'])],
                actions: [
                    new FakeActionMetadata('publish'),
                    new FakeActionMetadata('export', ['GET', 'POST'], ActionScope::Collection),
                ],
            ),
            FakeTypeMetadata::resource(type: 'people', fields: [Id::make()->example('1')->build()]),
        ], atomicOperations: new FakeAtomicOperationsMetadata());
        $scope = ErrorScope::on('blog-posts', 'updateRelationship.author', 'fetchRelated.author', 'action.publish.post', 'action.export.post')
            ->and(ErrorScope::kinds(OperationKind::Atomic));
        $document = (new OpenApiProjector())->project(new ContributingServerMetadata($server, new ScopedErrorSource($scope, PaymentRequired::class)))->toArray();

        self::assertSame('BlogPostsAuthorUpdateRelationship402', $this->errorDocument($document, '/blog-posts/{id}/relationships/author', 'patch', '402'));
        self::assertSame('BlogPostsAuthorFetchRelated402', $this->errorDocument($document, '/blog-posts/{id}/author', 'get', '402'));
        self::assertSame('BlogPostsPublishAction402', $this->errorDocument($document, '/blog-posts/{id}/-actions/publish', 'post', '402'));
        self::assertSame('BlogPostsExportActionPost402', $this->errorDocument($document, '/blog-posts/-actions/export', 'post', '402'));
        self::assertArrayNotHasKey('402', $this->arr($document, 'paths', '/blog-posts/-actions/export', 'get', 'responses'));
        self::assertSame('AtomicOperations402', $this->errorDocument($document, '/operations', 'post', '402'));
    }

    #[Test]
    public function anOperationsErrorDocumentNameAnotherComponentHoldsIsRefused(): void
    {
        $server = new FakeServerMetadata(title: 'API', version: '1.0.0', types: [
            FakeTypeMetadata::resource(type: 'articles', fields: [
                Id::make()->example('1')->build(),
                Map::make('address')->fields(Str::make('city'))->component('ArticlesUpdate402')->build(),
            ]),
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The 402 error document of the operation "update.articles" would be emitted as the component "ArticlesUpdate402"');

        (new OpenApiProjector())->project(new ContributingServerMetadata($server, new ScopedErrorSource(ErrorScope::on('articles', 'update'), PaymentRequired::class)));
    }

    #[Test]
    public function twoOperationsNamingOneErrorDocumentAreRefused(): void
    {
        $server = new FakeServerMetadata(title: 'API', version: '1.0.0', types: [
            FakeTypeMetadata::resource(type: 'blog-posts', fields: [Id::make()->example('1')->build()]),
            FakeTypeMetadata::resource(type: 'blog_posts', fields: [Id::make()->example('1')->build()]),
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('would both name their 402 error document "BlogPostsUpdate402"');

        (new OpenApiProjector())->project(new ContributingServerMetadata($server, new ScopedErrorSource(ErrorScope::kinds(OperationKind::Update), PaymentRequired::class)));
    }

    #[Test]
    public function anOperationGainsTheStatusOfAScopedCodeInOrder(): void
    {
        $document = $this->project(new ClassListErrorSource(ArticleLocked::class));

        $responses = $this->arr($document, 'paths', '/articles/{id}', 'delete', 'responses');
        self::assertSame('#/components/schemas/ArticlesDelete409', $this->at($responses, '409', 'content', 'application/vnd.api+json', 'schema', '$ref'));
        $statuses = \array_map(\strval(...), \array_keys($responses));
        $sorted = $statuses;
        \sort($sorted, \SORT_STRING);
        self::assertSame($sorted, $statuses);
    }

    #[Test]
    public function aScopedSourceScopesAnErrorItDoesNotOwn(): void
    {
        $document = $this->project(new ScopedErrorSource(ErrorScope::on('articles', 'update'), PaymentRequired::class));

        self::assertSame('ArticlesUpdate402', $this->errorDocument($document, '/articles/{id}', 'patch', '402'));
        self::assertArrayNotHasKey('402', $this->arr($document, 'paths', '/articles/{id}', 'delete', 'responses'));
        // Nothing else answers 402, so no shared 402 response is left behind.
        self::assertArrayNotHasKey('PaymentRequired', $this->arr($document, 'components', 'responses'));
        self::assertSame(
            [['$ref' => '#/components/schemas/Error'], ['$ref' => '#/components/schemas/Error402PaymentRequired']],
            $this->at($document, 'components', 'schemas', 'ArticlesUpdate402', 'properties', 'errors', 'items', 'anyOf'),
        );
    }

    #[Test]
    public function aScopedSourceOverridesTheDescriptorsScopeWhicheverSourceComesFirst(): void
    {
        $document = $this->project(
            new ClassListErrorSource(ArticleLocked::class),
            new ScopedErrorSource(ErrorScope::on('articles', 'create'), ArticleLocked::class),
        );

        self::assertSame('ArticlesCreate409', $this->errorDocument($document, '/articles', 'post', '409'));
        self::assertSame('#/components/responses/Conflict', $this->at($document, 'paths', '/articles/{id}', 'patch', 'responses', '409', '$ref'));
    }

    #[Test]
    public function aTypeWithNoOperationNamedScopesEveryOperationOfIt(): void
    {
        $document = $this->project(new ScopedErrorSource(ErrorScope::on('articles'), PaymentRequired::class));

        $expected = [
            '/articles' => ['get' => 'ArticlesFetchCollection402', 'post' => 'ArticlesCreate402'],
            '/articles/{id}' => ['get' => 'ArticlesFetchOne402', 'patch' => 'ArticlesUpdate402', 'delete' => 'ArticlesDelete402'],
        ];
        foreach ($expected as $path => $methods) {
            foreach ($methods as $method => $name) {
                self::assertSame($name, $this->errorDocument($document, $path, $method, '402'), "{$method} {$path}");
            }
        }
    }

    #[Test]
    public function aKindScopeCoversThatOperationOnEveryType(): void
    {
        $document = $this->project(new ScopedErrorSource(ErrorScope::kinds(OperationKind::Delete), PaymentRequired::class));

        self::assertSame('ArticlesDelete402', $this->errorDocument($document, '/articles/{id}', 'delete', '402'));
        self::assertSame('TagsDelete402', $this->errorDocument($document, '/tags/{id}', 'delete', '402'));
        self::assertArrayNotHasKey('402', $this->arr($document, 'paths', '/articles/{id}', 'patch', 'responses'));
    }

    #[Test]
    public function anAtomicKindScopeCoversTheAtomicOperationsEndpoint(): void
    {
        $document = $this->projectWithAtomic(new ScopedErrorSource(ErrorScope::kinds(...OperationKind::cases()), PaymentRequired::class));

        self::assertSame('AtomicOperations402', $this->errorDocument($document, '/operations', 'post', '402'));
        self::assertSame('ArticlesUpdate402', $this->errorDocument($document, '/articles/{id}', 'patch', '402'));
        // Every operation answers 402 with its own document, so none is left on the shared one.
        self::assertArrayNotHasKey('PaymentRequired', $this->arr($document, 'components', 'responses'));
    }

    #[Test]
    public function theAtomicEndpointKeepsItsOwnWordingWhenNarrowed(): void
    {
        $document = $this->projectWithAtomic(new ScopedErrorSource(ErrorScope::kinds(OperationKind::Atomic), ArticleLocked::class));

        $response = $this->arr($document, 'paths', '/operations', 'post', 'responses', '409');
        self::assertSame('AtomicOperations409', $this->errorDocument($document, '/operations', 'post', '409'));
        self::assertStringContainsString('an operation conflicts', \is_string($response['description'] ?? null) ? $response['description'] : '');
        self::assertSame('#/components/responses/Conflict', $this->at($document, 'paths', '/articles/{id}', 'patch', 'responses', '409', '$ref'));
    }

    #[Test]
    public function aScopedWriteIsAlsoOfferedOnTheBatchThatCanPerformIt(): void
    {
        $document = $this->projectWithAtomic(new ScopedErrorSource(ErrorScope::on('articles', 'create'), PaymentRequired::class));

        self::assertSame('AtomicOperations402', $this->errorDocument($document, '/operations', 'post', '402'));
        self::assertSame('ArticlesCreate402', $this->errorDocument($document, '/articles', 'post', '402'));
    }

    #[Test]
    public function aScopedReadLeavesTheBatchAlone(): void
    {
        $document = $this->projectWithAtomic(new ScopedErrorSource(ErrorScope::on('articles', 'fetchOne')->and(ErrorScope::kinds(OperationKind::FetchCollection)), PaymentRequired::class));

        self::assertArrayNotHasKey('402', $this->arr($document, 'paths', '/operations', 'post', 'responses'));
    }

    #[Test]
    public function aScopedWriteNeedsTheBatchToBeEnabled(): void
    {
        $document = $this->project(new ScopedErrorSource(ErrorScope::on('articles', 'create'), PaymentRequired::class));

        self::assertArrayNotHasKey('/operations', $this->arr($document, 'paths'));
    }

    #[Test]
    public function aScopeNamingATypeTheServerDoesNotRegisterIsOfferedNowhere(): void
    {
        $document = $this->project(new ScopedErrorSource(ErrorScope::on('invoices', 'update'), PaymentRequired::class));

        self::assertArrayHasKey('Error402PaymentRequired', $this->arr($document, 'components', 'schemas'));
        self::assertArrayNotHasKey('402', $this->arr($document, 'paths', '/articles/{id}', 'patch', 'responses'));
    }

    #[Test]
    public function eachCodesExampleIsBuiltOnceAndReferencedWhereItIsOffered(): void
    {
        $document = $this->project(new ClassListErrorSource(ArticleLocked::class));

        self::assertSame('Article locked', $this->at($document, 'components', 'examples', 'ARTICLE_LOCKED', 'summary'));
        self::assertSame(
            ['$ref' => '#/components/examples/ARTICLE_LOCKED'],
            $this->at($document, 'paths', '/articles/{id}', 'patch', 'responses', '409', 'content', 'application/vnd.api+json', 'examples', 'ARTICLE_LOCKED'),
        );
        self::assertArrayNotHasKey('ARTICLE_LOCKED', $this->arr($document, 'components', 'responses', 'Conflict', 'content', 'application/vnd.api+json', 'examples'));
    }

    #[Test]
    public function aScopeNamingSomethingThatIsNotAnOperationIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('names the operation "publish" of the type "articles"');

        ErrorScope::on('articles', 'publish');
    }

    #[Test]
    public function scopesCombine(): void
    {
        $scope = ErrorScope::on('articles', 'update')->and(ErrorScope::on('articles', 'delete'))->and(ErrorScope::kinds(OperationKind::Create));

        self::assertSame(['articles' => ['update', 'delete']], $scope->operations);
        self::assertSame([OperationKind::Create], $scope->kinds);
        self::assertSame(['articles' => []], ErrorScope::on('articles', 'update')->and(ErrorScope::on('articles'))->operations);
        self::assertSame(
            ['tags' => ['create'], 'articles' => ['update']],
            ErrorScope::on('tags', 'create')->and(ErrorScope::on('articles', 'update'))->operations,
            'a type only the other scope names keeps the operations it names',
        );
    }

    #[Test]
    public function aScopedCodeClaimingACataloguedCodeIsRefused(): void
    {
        $this->expectException(\LogicException::class);

        $this->project(new ScopedErrorSource(ErrorScope::on('articles', 'update'), RivalResourceNotFound::class));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function project(ErrorCatalogSourceInterface ...$sources): array
    {
        return $this->projectServer(null, ...$sources);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function projectWithAtomic(ErrorCatalogSourceInterface ...$sources): array
    {
        return $this->projectServer(new FakeAtomicOperationsMetadata(), ...$sources);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function projectServer(?FakeAtomicOperationsMetadata $atomic, ErrorCatalogSourceInterface ...$sources): array
    {
        $server = new FakeServerMetadata(title: 'API', version: '1.0.0', types: [
            FakeTypeMetadata::resource(type: 'articles', fields: [Id::make()->example('1')->build(), Str::make('title')->example('Hello')->build()]),
            FakeTypeMetadata::resource(type: 'tags', fields: [Id::make()->example('1')->build(), Str::make('name')->example('news')->build()]),
        ], atomicOperations: $atomic);

        return (new OpenApiProjector())->project(new ContributingServerMetadata($server, ...$sources))->toArray();
    }

    /**
     * The name of the error document an operation answers `$status` with inline.
     *
     * @param array<array-key, mixed> $document
     */
    private function errorDocument(array $document, string $path, string $method, string $status): string
    {
        $ref = $this->at($document, 'paths', $path, $method, 'responses', $status, 'content', 'application/vnd.api+json', 'schema', '$ref');
        self::assertIsString($ref);

        return \substr($ref, \strlen('#/components/schemas/'));
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private function at(array $value, string ...$keys): mixed
    {
        $cursor = $value;
        foreach ($keys as $key) {
            self::assertIsArray($cursor);
            self::assertArrayHasKey($key, $cursor);
            $cursor = $cursor[$key];
        }

        return $cursor;
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    private function arr(array $value, string ...$keys): array
    {
        $found = $this->at($value, ...$keys);
        self::assertIsArray($found);

        return $found;
    }
}
