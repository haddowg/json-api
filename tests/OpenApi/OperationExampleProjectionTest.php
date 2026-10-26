<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\Exception\ClassListErrorSource;
use haddowg\JsonApi\OpenApi\ExampleDocumentBuilder;
use haddowg\JsonApi\OpenApi\GeneratorContract;
use haddowg\JsonApi\OpenApi\LinkSchemaProjector;
use haddowg\JsonApi\OpenApi\Metadata\ActionInputMode;
use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\OperationExample;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApi\OpenApi\MissingExample;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\OperationExampleProjector;
use haddowg\JsonApi\OpenApi\Paths;
use haddowg\JsonApi\OpenApi\Projection;
use haddowg\JsonApi\OpenApi\Server;
use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Pagination\OffsetPaginator;
use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\ContributingServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeActionMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\LinkDeclaringServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\QuotaParameterRejected;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OperationExampleProjector::class)]
#[CoversClass(ExampleDocumentBuilder::class)]
#[CoversClass(MissingExample::class)]
#[CoversClass(Projection::class)]
final class OperationExampleProjectionTest extends TestCase
{
    #[Test]
    public function aWriteCarriesTheWritableFieldsAndAReadTheReadableOnes(): void
    {
        $document = $this->project([$this->articles()]);

        $create = self::requestExample($document, '/articles', 'post');
        self::assertSame(
            ['type' => 'articles', 'attributes' => ['title' => 'Hello', 'secret' => 'hunter2', 'summary' => 'Short']],
            self::at($create, 'data'),
            'a create sets no read-only field, sends its write-only one, and carries no id the server assigns',
        );

        $update = self::requestExample($document, '/articles/{id}', 'patch');
        self::assertSame(['type' => 'articles', 'id' => 'art-1', 'attributes' => ['title' => 'Hello', 'secret' => 'hunter2', 'summary' => 'Short']], self::at($update, 'data'));

        $read = self::responseExample($document, '/articles/{id}', 'get', '200');
        // A sparse-by-default member renders only when a fieldset names it.
        self::assertSame(['title' => 'Hello', 'views' => 10], self::at($read, 'data', 'attributes'));
        self::assertSame(['self' => ['href' => 'https://api.example.com/articles/art-1']], self::at($read, 'data', 'links'));
        self::assertSame(['self' => ['href' => 'https://api.example.com/articles/art-1']], self::at($read, 'links'));
        self::assertSame(['version' => '1.1'], self::at($read, 'jsonapi'));

        // A create answers with the created resource; its document's `self` is the URL
        // the request went to.
        $created = self::responseExample($document, '/articles', 'post', '201');
        self::assertSame(['self' => ['href' => 'https://api.example.com/articles']], self::at($created, 'links'));
    }

    #[Test]
    public function aCreateCarriesTheIdOnlyWhereAClientMaySupplyOne(): void
    {
        $fields = [Id::make()->example('p-1')->build(), Str::make('name')->example('Ada')->build()];
        $document = $this->project([
            FakeTypeMetadata::resource('optional', $fields, allowsClientId: true),
            FakeTypeMetadata::resource('mandatory', $fields, allowsClientId: true, requiresClientId: true),
        ]);

        self::assertSame('p-1', self::at(self::requestExample($document, '/optional', 'post'), 'data', 'id'));
        self::assertSame('p-1', self::at(self::requestExample($document, '/mandatory', 'post'), 'data', 'id'));
    }

    #[Test]
    public function aNestedObjectIsBuiltFromItsChildrenAndADeclaredNullStaysNull(): void
    {
        $type = FakeTypeMetadata::resource('places', [
            Id::make()->example('pl-1')->build(),
            Obj::make('address')->fields(
                Str::make('street')->example('1 Main St')->build(),
                Str::make('pin')->writeOnly()->example('1234')->build(),
                Obj::make('geo')->fields(Integer::make('lat')->example(51)->build())->build(),
            )->build(),
            Str::make('nickname')->example(null)->build(),
            Obj::make('settings')->example(['theme' => 'dark'])->fields(Str::make('theme')->build())->build(),
        ]);
        $document = $this->project([$type]);

        $read = self::responseExample($document, '/places/{id}', 'get', '200');
        self::assertSame([
            'address' => ['street' => '1 Main St', 'geo' => ['lat' => 51]],
            'nickname' => null,
            'settings' => ['theme' => 'dark'],
        ], self::at($read, 'data', 'attributes'));

        $create = self::requestExample($document, '/places', 'post');
        self::assertSame(['street' => '1 Main St', 'pin' => '1234', 'geo' => ['lat' => 51]], self::at($create, 'data', 'attributes', 'address'));
    }

    #[Test]
    public function linkageIsBuiltFromTheRelatedTypesIdExample(): void
    {
        $articles = FakeTypeMetadata::resource('articles', [Id::make()->example('art-1')->build()], relations: [
            FakeRelationMetadata::toOne('author', ['people']),
            new FakeRelationMetadata('tags', ['tags'], true),
            // Polymorphic: the example shows its first type.
            FakeRelationMetadata::toOne('featured', ['tags', 'people']),
        ]);
        $people = FakeTypeMetadata::resource('people', [Id::make()->example('per-1')->build()]);
        $tags = FakeTypeMetadata::resource('tags', [Id::make()->example('tag-1')->build()]);
        $document = $this->project([$articles, $people, $tags]);

        $read = self::responseExample($document, '/articles/{id}', 'get', '200');

        self::assertSame(['type' => 'people', 'id' => 'per-1'], self::at($read, 'data', 'relationships', 'author', 'data'));
        self::assertSame([['type' => 'tags', 'id' => 'tag-1']], self::at($read, 'data', 'relationships', 'tags', 'data'));
        self::assertSame(['type' => 'tags', 'id' => 'tag-1'], self::at($read, 'data', 'relationships', 'featured', 'data'));
        self::assertSame([
            'self' => ['href' => 'https://api.example.com/articles/art-1/relationships/author'],
            'related' => ['href' => 'https://api.example.com/articles/art-1/author'],
        ], self::at($read, 'data', 'relationships', 'author', 'links'));

        self::assertSame(['data' => [['type' => 'tags', 'id' => 'tag-1']]], self::requestExample($document, '/articles/{id}/relationships/tags', 'post'));
        $linkage = self::responseExample($document, '/articles/{id}/relationships/author', 'get', '200');
        self::assertSame(['type' => 'people', 'id' => 'per-1'], self::at($linkage, 'data'));
        self::assertSame(['href' => 'https://api.example.com/articles/art-1/relationships/author'], self::at($linkage, 'links', 'self'));

        self::assertSame(['type' => 'people', 'id' => 'per-1'], self::at(self::requestExample($document, '/articles', 'post'), 'data', 'relationships', 'author', 'data'));
    }

    #[Test]
    public function aCountableCollectionShowsTheTotalItsCountedPageRenders(): void
    {
        $type = FakeTypeMetadata::resource('tags', [Id::make()->example('tag-1')->build()], countable: true);
        $document = $this->project([$type], profiles: [CountableProfile::URI]);

        $collection = self::responseExample($document, '/tags', 'get', '200');
        $page = static fn(int $number): array => ['href' => 'https://api.example.com/tags?page%5Bnumber%5D=' . $number . '&page%5Bsize%5D=15&withCount=_self_'];
        self::assertSame([
            'self' => $page(2),
            'first' => $page(1),
            'prev' => $page(1),
            'next' => $page(3),
            'last' => $page(5),
        ], self::at($collection, 'links'));
        self::assertSame([
            'total' => 75,
            'page' => ['currentPage' => 2, 'perPage' => 15, 'from' => 16, 'to' => 30, 'total' => 75, 'lastPage' => 5],
        ], self::at($collection, 'meta'));
        self::assertSame(['version' => '1.1', 'profile' => [CountableProfile::URI]], self::at($collection, 'jsonapi'));
    }

    #[Test]
    public function anUncountedCollectionRendersNoTotalAndNoLastPage(): void
    {
        $fields = [Id::make()->example('t-1')->build()];
        $document = $this->project([
            FakeTypeMetadata::resource('pages', $fields),
            FakeTypeMetadata::resource('offsets', $fields, pageSchema: OffsetPaginator::make()->describePageSchema()),
            FakeTypeMetadata::resource('cursors', $fields, pageSchema: CursorPaginator::make()->describePageSchema()),
            FakeTypeMetadata::resource('wholes', $fields, unpaginated: true),
        ]);

        $pages = self::responseExample($document, '/pages', 'get', '200');
        self::assertSame(['self', 'first', 'prev', 'next'], \array_keys(self::arr(self::at($pages, 'links'))));
        self::assertSame(['page' => ['currentPage' => 2, 'perPage' => 15, 'from' => 16, 'to' => 30]], self::at($pages, 'meta'));

        $offsets = self::responseExample($document, '/offsets', 'get', '200');
        self::assertSame(['offset', 'limit', 'from', 'to'], \array_keys(self::arr(self::at($offsets, 'meta', 'page'))));

        $cursors = self::responseExample($document, '/cursors', 'get', '200');
        self::assertSame(['first', 'prev', 'next', 'self'], \array_keys(self::arr(self::at($cursors, 'links'))));
        self::assertSame(['page' => ['perPage' => 15, 'from' => 't-1', 'to' => 't-1', 'hasMore' => true]], self::at($cursors, 'meta'));

        $wholes = self::responseExample($document, '/wholes', 'get', '200');
        self::assertSame(['self' => ['href' => 'https://api.example.com/wholes']], self::at($wholes, 'links'));
        self::assertSame(['total' => 1], self::at($wholes, 'meta'));
    }

    #[Test]
    public function linksTakeTheServersLinkObjectFormWithTheirDescribedBy(): void
    {
        $type = FakeTypeMetadata::resource('tags', [Id::make()->example('tag-1')->build()], unpaginated: true);
        $server = new LinkDeclaringServerMetadata($this->server([$type]), documentUrl: '/openapi.json');
        $document = (new OpenApiProjector())->project($server)->toArray();

        $read = self::responseExample($document, '/tags/{id}', 'get', '200');
        self::assertSame([
            'self' => ['href' => 'https://api.example.com/tags/tag-1', 'describedby' => '/openapi.json#/paths/~1tags~1%7Bid%7D/get'],
            'describedby' => ['href' => '/openapi.json#/paths/~1tags~1%7Bid%7D/get'],
        ], self::at($read, 'links'));
    }

    #[Test]
    public function anErrorResponseCarriesTheFirstCodeItsStatusCatalogues(): void
    {
        $document = $this->project([$this->articles()]);

        self::assertSame([
            'errors' => [[
                'status' => '406',
                'code' => 'MEDIA_TYPE_UNACCEPTABLE',
                'title' => 'The provided media type is unacceptable',
                'detail' => 'The media type \'application/vnd.api+json; version=2\' is unacceptable in the \'Accept\' header!',
                'source' => ['parameter' => 'accept'],
            ]],
            'jsonapi' => ['version' => '1.1'],
        ], self::at($document, 'components', 'responses', 'NotAcceptable', 'content', 'application/vnd.api+json', 'example'));

        // A status catalogues many codes; the example shows one error, never the whole list.
        self::assertCount(1, self::arr(self::at($document, 'components', 'responses', 'BadRequest', 'content', 'application/vnd.api+json', 'example', 'errors')));
    }

    #[Test]
    public function anErrorResponsePrefersTheCodeAReaderMostLikelyMeets(): void
    {
        $document = $this->project([$this->articles()]);
        $code = static fn(string $response): mixed => self::at($document, 'components', 'responses', $response, 'content', 'application/vnd.api+json', 'example', 'errors', '0', 'code');

        self::assertSame('QUERY_PARAM_MALFORMED', $code('BadRequest'));
        self::assertSame('ACCESS_DENIED', $code('Forbidden'));
        self::assertSame('RESOURCE_NOT_FOUND', $code('NotFound'));
        self::assertSame('RESOURCE_TYPE_UNACCEPTABLE', $code('Conflict'));
        self::assertSame('ATTRIBUTE_VALUE_INVALID', $code('UnprocessableEntity'));
        // A status with no preferred code keeps the first one catalogued.
        self::assertSame('MEDIA_TYPE_UNSUPPORTED', $code('UnsupportedMediaType'));
    }

    #[Test]
    public function aContributedCodeLaterInTheCatalogueLeavesTheExampleAlone(): void
    {
        $server = new ContributingServerMetadata($this->server([$this->articles()]), new ClassListErrorSource(QuotaParameterRejected::class));
        $projection = (new OpenApiProjector())->projectWithDiagnostics($server);

        self::assertNotNull(self::at($projection->document->toArray(), 'components', 'responses', 'BadRequest', 'content', 'application/vnd.api+json', 'example'));
        self::assertNotContainsEquals(new MissingExample('BadRequest', 'QUOTA_PARAMETER_REJECTED', 'source'), $projection->missingExamples);
    }

    #[Test]
    public function anErrorCodeNamingASourceWithNoExampleOfOneBuildsNoExample(): void
    {
        $server = $this->server([]);
        $builder = new ExampleDocumentBuilder($server, new LinkSchemaProjector($server, new Paths()));

        [$example, $misses] = $builder->attempt(static fn(): array => $builder->errorDocument(QuotaParameterRejected::describe()));

        self::assertNull($example);
        self::assertSame([['QUOTA_PARAMETER_REJECTED', 'source']], $misses);
    }

    #[Test]
    public function aMissingExampleLeavesTheOperationWithoutOneAndSaysWhichField(): void
    {
        $type = FakeTypeMetadata::resource('notes', [
            Id::make()->example('n-1')->build(),
            Str::make('body')->build(),
            Str::make('draft')->readOnly()->build(),
        ]);
        $projection = (new OpenApiProjector())->projectWithDiagnostics($this->server([$type]));
        $document = $projection->document->toArray();

        self::assertNull(self::requestExample($document, '/notes', 'post'));
        self::assertNull(self::responseExample($document, '/notes/{id}', 'get', '200'));

        $missing = \array_map(static fn(MissingExample $m): string => (string) $m, $projection->missingExamples);
        self::assertContains('create.notes has no example: "notes" declares no example for "body".', $missing);
        self::assertContains('fetchOne.notes has no example: "notes" declares no example for "draft".', $missing);
        self::assertNotContains('update.notes has no example: "notes" declares no example for "id".', $missing);
    }

    #[Test]
    public function anOverrideReplacesOrSwitchesOffAnOperationsExample(): void
    {
        $type = FakeTypeMetadata::resource(
            'notes',
            [Id::make()->example('n-1')->build(), Str::make('body')->build()],
            actions: [
                new FakeActionMetadata('archive', ['POST'], ActionScope::Resource, ActionInputMode::Document, inputType: 'notes', outputType: 'notes', example: false),
                new FakeActionMetadata('copy', ['POST'], ActionScope::Resource, ActionInputMode::None, outputType: 'notes', example: new OperationExample(responses: [200 => ['data' => ['type' => 'notes', 'id' => 'n-2']]])),
            ],
            operationExamples: [
                'create' => new OperationExample(request: ['data' => ['type' => 'notes', 'attributes' => ['body' => 'Hi']]]),
                'update' => false,
            ],
        );
        $projection = (new OpenApiProjector())->projectWithDiagnostics($this->server([$type]));
        $document = $projection->document->toArray();

        self::assertSame(['data' => ['type' => 'notes', 'attributes' => ['body' => 'Hi']]], self::requestExample($document, '/notes', 'post'));
        // The parts it does not name are generated, and still need their examples.
        self::assertNull(self::responseExample($document, '/notes', 'post', '201'));
        self::assertNull(self::requestExample($document, '/notes/{id}', 'patch'));
        self::assertSame(['data' => ['type' => 'notes', 'id' => 'n-2']], self::responseExample($document, '/notes/{id}/-actions/copy', 'post', '200'));

        $operations = \array_unique(\array_map(static fn(MissingExample $m): string => $m->operation, $projection->missingExamples));
        self::assertNotContains('update.notes', $operations, 'a switched-off operation reports nothing missing');
        self::assertNotContains('action.notes.archive', $operations);
        self::assertContains('create.notes', $operations);
    }

    #[Test]
    public function anOverrideNamingAnOperationTheTypeDoesNotExposeFails(): void
    {
        $type = FakeTypeMetadata::resource('notes', [Id::make()->example('n-1')->build()], operationExamples: ['remove' => false]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"remove"');

        (new OpenApiProjector())->project($this->server([$type]));
    }

    #[Test]
    public function aServerCanSwitchGenerationOff(): void
    {
        $projection = (new OpenApiProjector())->projectWithDiagnostics($this->server([$this->articles()], examples: false));
        $document = $projection->document->toArray();

        self::assertNull(self::requestExample($document, '/articles', 'post'));
        self::assertNull(self::at($document, 'components', 'responses', 'NotAcceptable', 'content', 'application/vnd.api+json', 'example'));
        self::assertSame([], $projection->missingExamples);
        self::assertSame(['contract' => GeneratorContract::BASELINE], self::at($document, 'info', 'x-generator'));
    }

    #[Test]
    public function aDocumentWithAnExampleCarriesTheExamplesContract(): void
    {
        self::assertSame(['contract' => GeneratorContract::EXAMPLES], self::at($this->project([$this->articles()]), 'info', 'x-generator'));
    }

    private function articles(): TypeMetadataInterface
    {
        return FakeTypeMetadata::resource('articles', [
            Id::make()->example('art-1')->build(),
            Str::make('title')->example('Hello')->build(),
            Integer::make('views')->readOnly()->example(10)->build(),
            Str::make('secret')->writeOnly()->example('hunter2')->build(),
            Str::make('internal')->hidden()->build(),
            Str::make('summary')->sparseByDefault()->example('Short')->build(),
        ], pageSchema: PagePaginator::make()->describePageSchema());
    }

    /**
     * @param list<TypeMetadataInterface> $types
     * @param list<string>                $profiles
     */
    private function server(array $types, array $profiles = [], bool $examples = true): FakeServerMetadata
    {
        return new FakeServerMetadata(
            'Examples',
            '1',
            $types,
            servers: [new Server('https://api.example.com')],
            profiles: $profiles,
            generatesExamples: $examples,
        );
    }

    /**
     * @param list<TypeMetadataInterface> $types
     * @param list<string>                $profiles
     *
     * @return array<string, mixed>
     */
    private function project(array $types, array $profiles = []): array
    {
        /** @var array<string, mixed> */
        return (new OpenApiProjector())->project($this->server($types, $profiles))->toArray();
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>|null
     */
    private static function requestExample(array $document, string $path, string $method): ?array
    {
        $example = self::at($document, 'paths', $path, $method, 'requestBody', 'content', 'application/vnd.api+json', 'example');

        return $example === null ? null : self::arr($example);
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>|null
     */
    private static function responseExample(array $document, string $path, string $method, string $status): ?array
    {
        $example = self::at($document, 'paths', $path, $method, 'responses', $status, 'content', 'application/vnd.api+json', 'example');

        return $example === null ? null : self::arr($example);
    }

    private static function at(mixed $value, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private static function arr(mixed $value): array
    {
        self::assertIsArray($value);

        /** @var array<string, mixed> $value */
        return $value;
    }
}
