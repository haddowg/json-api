<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\Exception\CursorMalformed;
use haddowg\JsonApi\Exception\ErrorScope;
use haddowg\JsonApi\Exception\ScopedErrorSource;
use haddowg\JsonApi\OpenApi\ErrorCatalogProjector;
use haddowg\JsonApi\OpenApi\Metadata\ActionInputMode;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\OperationFacts;
use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Pagination\MultiPaginator;
use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\ContributingServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeActionMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeAtomicOperationsMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\ReadOnlyAttributeRejectingServerMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A code tied to an {@see \haddowg\JsonApi\Exception\ErrorFeature} is offered only by the
 * operations that can raise it, once the server offers the feature at all.
 */
#[CoversClass(ErrorCatalogProjector::class)]
#[CoversClass(OperationFacts::class)]
final class FeatureErrorScopeProjectionTest extends TestCase
{
    #[Test]
    public function theCursorCodesAreOfferedOnlyOnTheReadsThatPageByCursor(): void
    {
        $document = $this->project($this->server(
            articlePage: self::cursor(),
            relations: [
                new FakeRelationMetadata('comments', ['comments'], true, pageSchema: self::cursor()),
                new FakeRelationMetadata('tags', ['comments'], true, pageSchema: PagePaginator::make()->describePageSchema()),
            ],
        ));

        self::assertContains('CURSOR_MALFORMED', $this->codes($document, '/articles', 'get'));
        self::assertContains('CURSOR_STALE', $this->codes($document, '/articles', 'get'));
        self::assertNotContains('CURSOR_MALFORMED', $this->codes($document, '/articles/{id}', 'get'));
        self::assertNotContains('CURSOR_MALFORMED', $this->codes($document, '/articles/{id}', 'delete'));
        self::assertNotContains('CURSOR_MALFORMED', $this->codes($document, '/articles', 'post'));

        // A to-many paging by cursor offers them on its related and relationship reads; one
        // paging by number does not.
        self::assertContains('CURSOR_MALFORMED', $this->codes($document, '/articles/{id}/comments', 'get'));
        self::assertContains('CURSOR_MALFORMED', $this->codes($document, '/articles/{id}/relationships/comments', 'get'));
        self::assertNotContains('CURSOR_MALFORMED', $this->codes($document, '/articles/{id}/tags', 'get'));
        self::assertNotContains('CURSOR_MALFORMED', $this->codes($document, '/articles/{id}/relationships/tags', 'get'));
        self::assertNotContains('CURSOR_MALFORMED', $this->codes($document, '/comments', 'get'));

        self::assertSame('ArticlesFetchCollection400', $this->errorDocument($document, '/articles', 'get', '400'));
        self::assertSame('#/components/responses/BadRequest', $this->at($document, 'paths', '/articles/{id}', 'get', 'responses', '400', '$ref'));
        self::assertNotContains('Error400ParameterCursorMalformed', $this->offered($document, 'ErrorDocument400'));
    }

    #[Test]
    public function theCursorCodesKeepTheirShapeNameWhateverElseReachesTheSameOperations(): void
    {
        // Cursor pages and withCount reach exactly the same operation here, yet each feature
        // keeps a shape of its own.
        $document = $this->project($this->server(
            articlePage: self::cursor(),
            countable: true,
            profiles: [CountableProfile::URI],
        ));

        self::assertSame(
            ['Error', 'Error400Parameter', 'Error400ParameterCursorMalformed', 'Error400ParameterRelationshipCountNotAllowed'],
            $this->offered($document, 'ArticlesFetchCollection400'),
        );
    }

    #[Test]
    public function thePaginationMenuCodeIsOfferedOnlyWhereThePageIsAMenu(): void
    {
        $document = $this->project($this->server(
            articlePage: (new MultiPaginator(PagePaginator::make(), CursorPaginator::make()))->describePageSchema(),
            commentPage: PagePaginator::make()->describePageSchema(),
        ));

        self::assertContains('PAGINATION_KIND_UNKNOWN', $this->codes($document, '/articles', 'get'));
        self::assertContains('CURSOR_MALFORMED', $this->codes($document, '/articles', 'get'), 'a menu holding a cursor arm pages by cursor too');
        self::assertNotContains('PAGINATION_KIND_UNKNOWN', $this->codes($document, '/comments', 'get'));
        self::assertNotContains('PAGINATION_KIND_UNKNOWN', $this->codes($document, '/articles/{id}', 'get'));
    }

    #[Test]
    public function theCountCodeIsOfferedOnlyOnTheReadsThatTakeWithCount(): void
    {
        $document = $this->project($this->server(
            countable: true,
            relations: [new FakeRelationMetadata('comments', ['comments'], true, countable: true)],
            profiles: [CountableProfile::URI],
        ));

        self::assertContains('RELATIONSHIP_COUNT_NOT_ALLOWED', $this->codes($document, '/articles', 'get'));
        self::assertContains('RELATIONSHIP_COUNT_NOT_ALLOWED', $this->codes($document, '/articles/{id}/comments', 'get'));
        self::assertContains('RELATIONSHIP_COUNT_NOT_ALLOWED', $this->codes($document, '/articles/{id}/relationships/comments', 'get'));
        self::assertNotContains('RELATIONSHIP_COUNT_NOT_ALLOWED', $this->codes($document, '/articles/{id}', 'get'));
        self::assertNotContains('RELATIONSHIP_COUNT_NOT_ALLOWED', $this->codes($document, '/articles/{id}', 'delete'));
        // Nothing on a comment counts, so its collection takes no withCount.
        self::assertNotContains('RELATIONSHIP_COUNT_NOT_ALLOWED', $this->codes($document, '/comments', 'get'));
    }

    #[Test]
    public function theClientIdCodesAreOfferedOnlyOnTheCreatesThatTakeOne(): void
    {
        $document = $this->project($this->server(
            clientId: true,
            articleActions: [new FakeActionMetadata('import', ['POST'], inputMode: ActionInputMode::Document, inputType: 'articles')],
        ));

        foreach (['CLIENT_GENERATED_ID_REQUIRED', 'CLIENT_GENERATED_ID_ALREADY_EXISTS'] as $code) {
            self::assertContains($code, $this->codes($document, '/articles', 'post'), $code);
            self::assertContains($code, $this->codes($document, '/articles/{id}/-actions/import', 'post'), 'an action hydrating the type as a create');
            self::assertNotContains($code, $this->codes($document, '/articles/{id}', 'patch'), $code);
            self::assertNotContains($code, $this->codes($document, '/comments', 'post'), $code);
        }
        // A type that refuses a client id says so on its create.
        self::assertContains('CLIENT_GENERATED_ID_NOT_SUPPORTED', $this->codes($document, '/comments', 'post'));
    }

    #[Test]
    public function aFeatureNoOperationReachesLeavesItsCodesOut(): void
    {
        // The type permits a client id but exposes no create, so nothing can raise the codes.
        $document = $this->project($this->server(
            clientId: true,
            operations: [OperationType::FetchCollection, OperationType::FetchOne, OperationType::Update],
        ));

        self::assertArrayNotHasKey('Error403PointerClientGeneratedIdRequired', $this->arr($document, 'components', 'schemas'));
        self::assertArrayNotHasKey('Error409PointerClientGeneratedIdAlreadyExists', $this->arr($document, 'components', 'schemas'));
    }

    #[Test]
    public function theRequestDocumentCodesAreOfferedOnlyOnTheOperationsThatReadADocument(): void
    {
        $document = $this->project($this->server(
            relations: [new FakeRelationMetadata('comments', ['comments'], true)],
            articleActions: [
                new FakeActionMetadata('import', ['POST'], inputMode: ActionInputMode::Document, inputType: 'articles'),
                new FakeActionMetadata('publish', ['POST']),
            ],
            atomic: true,
        ));

        $reading = [
            ['/articles', 'post'], ['/articles/{id}', 'patch'],
            ['/articles/{id}/relationships/comments', 'patch'], ['/articles/{id}/relationships/comments', 'post'], ['/articles/{id}/relationships/comments', 'delete'],
            ['/articles/{id}/-actions/import', 'post'], ['/operations', 'post'],
        ];
        foreach ($reading as [$path, $method]) {
            self::assertContains('REQUEST_BODY_INVALID_JSON', $this->codes($document, $path, $method), "{$method} {$path}");
            self::assertContains('DATA_MEMBER_MISSING', $this->codes($document, $path, $method), "{$method} {$path}");
        }

        $notReading = [
            ['/articles', 'get'], ['/articles/{id}', 'get'], ['/articles/{id}', 'delete'],
            ['/articles/{id}/comments', 'get'], ['/articles/{id}/relationships/comments', 'get'],
            ['/articles/{id}/-actions/publish', 'post'],
        ];
        foreach ($notReading as [$path, $method]) {
            self::assertNotContains('REQUEST_BODY_INVALID_JSON', $this->codes($document, $path, $method), "{$method} {$path}");
            self::assertNotContains('RESOURCE_TYPE_UNACCEPTABLE', $this->codes($document, $path, $method), "{$method} {$path}");
        }
        self::assertArrayNotHasKey('409', $this->arr($document, 'paths', '/articles/{id}', 'delete', 'responses'));
    }

    #[Test]
    public function theReadOnlyAttributeCodeIsOfferedOnlyWhereAResourceIsHydrated(): void
    {
        $document = $this->project(new ReadOnlyAttributeRejectingServerMetadata($this->server(
            relations: [new FakeRelationMetadata('comments', ['comments'], true)],
        )));

        self::assertContains('ATTRIBUTE_WRITE_PROHIBITED', $this->codes($document, '/articles', 'post'));
        self::assertContains('ATTRIBUTE_WRITE_PROHIBITED', $this->codes($document, '/articles/{id}', 'patch'));
        self::assertNotContains('ATTRIBUTE_WRITE_PROHIBITED', $this->codes($document, '/articles/{id}/relationships/comments', 'patch'));
        self::assertNotContains('ATTRIBUTE_WRITE_PROHIBITED', $this->codes($document, '/articles/{id}', 'get'));
    }

    #[Test]
    public function theFixedMembershipCodeIsOfferedOnlyWhereAFixedRelationCanBeReplaced(): void
    {
        $document = $this->project($this->server(
            relations: [
                new FakeRelationMetadata('tracks', ['comments'], true, allowsAdd: false, allowsRemove: false, fixesMembership: true),
                new FakeRelationMetadata('comments', ['comments'], true),
            ],
        ));

        self::assertContains('MEMBERSHIP_CHANGE_PROHIBITED', $this->codes($document, '/articles/{id}/relationships/tracks', 'patch'));
        self::assertContains('MEMBERSHIP_CHANGE_PROHIBITED', $this->codes($document, '/articles/{id}', 'patch'));
        self::assertNotContains('MEMBERSHIP_CHANGE_PROHIBITED', $this->codes($document, '/articles/{id}/relationships/comments', 'patch'));
        self::assertNotContains('MEMBERSHIP_CHANGE_PROHIBITED', $this->codes($document, '/articles', 'post'), 'a create sets the members freely');
        self::assertNotContains('MEMBERSHIP_CHANGE_PROHIBITED', $this->codes($document, '/comments/{id}', 'patch'));
    }

    #[Test]
    public function theLocalIdAndBatchCodesAreOfferedOnlyOnTheBatch(): void
    {
        $document = $this->project($this->server(atomic: true));

        foreach (['LOCAL_ID_CONFLICT', 'LOCAL_ID_NOT_FOUND', 'ATOMIC_OPERATIONS_INVALID'] as $code) {
            self::assertContains($code, $this->codes($document, '/operations', 'post'), $code);
            self::assertNotContains($code, $this->codes($document, '/articles', 'post'), $code);
            self::assertNotContains($code, $this->codes($document, '/articles', 'get'), $code);
        }
    }

    #[Test]
    public function aDeclaredScopeReplacesTheFeaturesOwn(): void
    {
        $server = new ContributingServerMetadata(
            $this->server(articlePage: self::cursor()),
            new ScopedErrorSource(ErrorScope::on('articles', 'fetchOne'), CursorMalformed::class),
        );
        $document = $this->project($server);

        self::assertContains('CURSOR_MALFORMED', $this->codes($document, '/articles/{id}', 'get'));
        self::assertNotContains('CURSOR_MALFORMED', $this->codes($document, '/articles', 'get'));
        self::assertContains('CURSOR_STALE', $this->codes($document, '/articles', 'get'));
    }

    private static function cursor(): Schema
    {
        return CursorPaginator::make()->describePageSchema();
    }

    /**
     * An `articles` and a `comments` type, each fully writable unless told otherwise.
     *
     * @param list<FakeRelationMetadata>  $relations      the articles' relations
     * @param list<FakeActionMetadata>    $articleActions
     * @param list<OperationType>|null    $operations     the articles' operations
     * @param list<string>                $profiles
     */
    private function server(
        ?Schema $articlePage = null,
        ?Schema $commentPage = null,
        bool $countable = false,
        bool $clientId = false,
        ?array $operations = null,
        array $relations = [],
        array $articleActions = [],
        array $profiles = [],
        bool $atomic = false,
    ): FakeServerMetadata {
        return new FakeServerMetadata(
            title: 'API',
            version: '1.0.0',
            types: [
                FakeTypeMetadata::resource(
                    type: 'articles',
                    fields: [Id::make()->example('1')->build(), Str::make('title')->example('Hello')->build()],
                    relations: $relations,
                    allowsClientId: $clientId,
                    requiresClientId: $clientId,
                    operations: $operations,
                    pageSchema: $articlePage,
                    countable: $countable,
                    actions: $articleActions,
                ),
                FakeTypeMetadata::resource(
                    type: 'comments',
                    fields: [Id::make()->example('1')->build(), Str::make('body')->example('Nice')->build()],
                    pageSchema: $commentPage,
                ),
            ],
            atomicOperations: $atomic ? new FakeAtomicOperationsMetadata() : null,
            profiles: $profiles,
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    private function project(ServerMetadataInterface $server): array
    {
        return (new OpenApiProjector())->project($server)->toArray();
    }

    /**
     * Every code an operation's error responses offer, across its statuses.
     *
     * @param array<array-key, mixed> $document
     *
     * @return list<string>
     */
    private function codes(array $document, string $path, string $method): array
    {
        $codes = [];
        foreach ($this->arr($document, 'paths', $path, $method, 'responses') as $status => $response) {
            if ((int) $status < 400) {
                continue;
            }
            self::assertIsArray($response);
            $ref = $response['$ref'] ?? null;
            if (\is_string($ref)) {
                $response = $this->arr($document, 'components', 'responses', \substr($ref, \strlen('#/components/responses/')));
            }
            $schema = $this->at($response, 'content', 'application/vnd.api+json', 'schema', '$ref');
            self::assertIsString($schema);
            foreach ($this->offered($document, \substr($schema, \strlen('#/components/schemas/'))) as $shape) {
                if ($shape === 'Error') {
                    continue;
                }
                foreach ($this->arr($document, 'components', 'schemas', $shape, 'allOf', '1', 'properties', 'code', 'anyOf') as $branch) {
                    self::assertIsArray($branch);
                    self::assertIsString($branch['const'] ?? null);
                    $codes[] = $branch['const'];
                }
            }
        }

        return $codes;
    }

    /**
     * The shapes an error document offers, behind the generic `Error`.
     *
     * @param array<array-key, mixed> $document
     *
     * @return list<string>
     */
    private function offered(array $document, string $errorDocument): array
    {
        $shapes = [];
        foreach ($this->arr($document, 'components', 'schemas', $errorDocument, 'properties', 'errors', 'items', 'anyOf') as $branch) {
            self::assertIsArray($branch);
            self::assertIsString($branch['$ref'] ?? null);
            $shapes[] = \substr($branch['$ref'], \strlen('#/components/schemas/'));
        }

        return $shapes;
    }

    /**
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
