<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\GeneratorContract;
use haddowg\JsonApi\OpenApi\LinkSchemaProjector;
use haddowg\JsonApi\OpenApi\Metadata\ActionInputMode;
use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\OpenApi\Server;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeActionMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\LinkDeclaringServerMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Each links context has one shared component listing the members it can render, as the
 * link objects the server renders. A type with named links extends the shared resource
 * links in its own component. The link schemas carry no examples: the operation examples
 * show the links a response renders, from the document's server URL and with a
 * `describedby` into the document when the server names its URL.
 */
#[CoversClass(LinkSchemaProjector::class)]
#[CoversClass(OpenApiProjector::class)]
final class LinkProjectionTest extends TestCase
{
    private const DOCS = '/api/v5/docs.json';

    private const PAGES = ['first', 'prev', 'next', 'last'];

    #[Test]
    public function aTypeWithActionLinksExtendsTheSharedResourceLinks(): void
    {
        $document = $this->project();

        $shared = $this->schema($document, 'ResourceLinks');
        self::assertSame(['self'], \array_keys($this->arrAt($shared, 'properties')));
        self::assertSame('#/components/schemas/RenderedLink', $this->at($shared, 'properties', 'self', '$ref'));
        self::assertSame(['$ref' => '#/components/schemas/LinkObject'], $this->at($shared, 'additionalProperties'));

        $links = $this->schema($document, 'AlbumsResourceLinks');
        self::assertSame(['description', 'allOf'], \array_keys($links));
        self::assertSame(['$ref' => '#/components/schemas/ResourceLinks'], $this->at($links, 'allOf', 0));
        // A collection action has no resource to hang a link off, and an action that
        // declares no link name renders none.
        self::assertSame(['publish'], \array_keys($this->arrAt($links, 'allOf', 1, 'properties')));
        self::assertSame(
            ['$ref' => '#/components/schemas/RenderedLink', 'description' => 'The `publish` action on this resource.'],
            $this->at($links, 'allOf', 1, 'properties', 'publish'),
        );

        self::assertSame('#/components/schemas/AlbumsResourceLinks', $this->at($this->schema($document, 'AlbumsResource'), 'properties', 'links', '$ref'));
    }

    #[Test]
    public function aTypeWithoutNamedLinksRefsTheSharedResourceLinks(): void
    {
        $document = $this->project();

        self::assertSame('#/components/schemas/ResourceLinks', $this->at($this->schema($document, 'TracksResource'), 'properties', 'links', '$ref'));
        self::assertArrayNotHasKey('TracksResourceLinks', $this->arrAt($document, 'components', 'schemas'));
    }

    #[Test]
    public function theDocumentsRefTheSharedDocumentAndCollectionLinks(): void
    {
        $document = $this->project();

        self::assertSame(['self', 'describedby'], \array_keys($this->arrAt($this->schema($document, 'DocumentLinks'), 'properties')));
        self::assertSame('#/components/schemas/DocumentLinks', $this->at($this->schema($document, 'AlbumsDocument'), 'properties', 'links', '$ref'));

        $collection = $this->schema($document, 'CollectionLinks');
        self::assertSame(['self', 'describedby', ...self::PAGES], \array_keys($this->arrAt($collection, 'properties')));
        self::assertSame('#/components/schemas/RenderedLink', $this->at($collection, 'properties', 'next', '$ref'));
        self::assertSame('#/components/schemas/CollectionLinks', $this->at($this->schema($document, 'AlbumsCollection'), 'properties', 'links', '$ref'));

        $schemas = $this->arrAt($document, 'components', 'schemas');
        self::assertArrayNotHasKey('AlbumsDocumentLinks', $schemas);
        self::assertArrayNotHasKey('AlbumsCollectionLinks', $schemas);
    }

    #[Test]
    public function everyRelationRefsTheSharedRelationshipComponents(): void
    {
        $document = $this->project();

        self::assertSame(['self', 'related', ...self::PAGES], \array_keys($this->arrAt($this->schema($document, 'RelationshipLinks'), 'properties')));
        self::assertSame(['self', 'related', ...self::PAGES, 'describedby'], \array_keys($this->arrAt($this->schema($document, 'RelationshipDocumentLinks'), 'properties')));
        self::assertSame(['self', 'describedby', ...self::PAGES], \array_keys($this->arrAt($this->schema($document, 'RelatedDocumentLinks'), 'properties')));

        // The artist relationship endpoint isn't exposed and a to-one never paginates,
        // but its relationship object takes the same shared links as the tracks one.
        self::assertSame('#/components/schemas/RelationshipLinks', $this->at($this->schema($document, 'AlbumsArtistRelationship'), 'properties', 'links', '$ref'));
        self::assertSame('#/components/schemas/RelationshipLinks', $this->at($this->schema($document, 'AlbumsTracksRelationship'), 'properties', 'links', '$ref'));
        self::assertSame('#/components/schemas/RelationshipDocumentLinks', $this->at($this->schema($document, 'AlbumsTracksRelationshipDocument'), 'properties', 'links', '$ref'));
        self::assertSame('#/components/schemas/RelatedDocumentLinks', $this->at($this->schema($document, 'AlbumsTracksRelatedCollection'), 'properties', 'links', '$ref'));
        self::assertSame('#/components/schemas/RelatedDocumentLinks', $this->at($this->schema($document, 'AlbumsArtistRelatedDocument'), 'properties', 'links', '$ref'));

        foreach (\array_keys($this->arrAt($document, 'components', 'schemas')) as $name) {
            self::assertDoesNotMatchRegularExpression('/^Albums.+(Relationship|RelationshipDocument|RelatedDocument)Links$/', (string) $name);
        }
    }

    #[Test]
    public function aServerEmitsOnlyTheLinksComponentsItsTypesUse(): void
    {
        $plain = new FakeServerMetadata(title: 'Music', version: '1', types: [FakeTypeMetadata::resource('tracks', [Id::make()->build()])], servers: [new Server('/api/v5')]);
        $schemas = $this->arrAt((new OpenApiProjector())->project($plain)->toArray(), 'components', 'schemas');
        foreach (['ResourceLinks', 'DocumentLinks', 'CollectionLinks'] as $name) {
            self::assertArrayHasKey($name, $schemas);
        }
        foreach (['RelationshipLinks', 'RelationshipDocumentLinks', 'RelatedDocumentLinks'] as $name) {
            self::assertArrayNotHasKey($name, $schemas);
        }

        $empty = new FakeServerMetadata(title: 'Music', version: '1', types: [], servers: [new Server('/api/v5')]);
        self::assertArrayNotHasKey('ResourceLinks', $this->arrAt((new OpenApiProjector())->project($empty)->toArray(), 'components', 'schemas'));
    }

    #[Test]
    public function theLinkSchemasCarryNoExamplesAndTheHrefNoteOnce(): void
    {
        $document = $this->project();

        foreach ($this->arrAt($document, 'components', 'schemas') as $name => $schema) {
            if (\str_ends_with((string) $name, 'Links') && $name !== 'Links' || $name === 'RenderedLink') {
                self::assertStringNotContainsString('"example"', (string) \json_encode($schema), $name . ' carries an example');
            }
        }

        $json = (string) \json_encode($document);
        self::assertSame(1, \substr_count($json, 'absolute to the request host'));
        self::assertStringContainsString('absolute to the request host', $this->str($this->schema($document, 'RenderedLink'), 'description'));
    }

    #[Test]
    public function theOperationExamplesCarryTheLinksTheSchemasNoLongerDo(): void
    {
        $document = $this->project();

        $resource = $this->at($this->responseExample($document, '/records/{id}', 'get'), 'data', 'links');
        self::assertSame(
            [
                'self' => ['href' => '/api/v5/records/alb-1', 'describedby' => self::DOCS . '#/paths/~1records~1%7Bid%7D/get'],
                'publish' => ['href' => '/api/v5/records/alb-1/-actions/publish', 'describedby' => self::DOCS . '#/paths/~1records~1%7Bid%7D~1-actions~1publish/post'],
            ],
            $resource,
        );
        self::assertSame(
            ['href' => self::DOCS . '#/paths/~1records~1%7Bid%7D/get'],
            $this->at($this->responseExample($document, '/records/{id}', 'get'), 'links', 'describedby'),
        );
        self::assertSame(
            ['href' => '/api/v5/records/alb-1/relationships/tracks', 'describedby' => self::DOCS . '#/paths/~1records~1%7Bid%7D~1relationships~1tracks/get'],
            $this->at($this->responseExample($document, '/records/{id}', 'get'), 'data', 'relationships', 'tracks', 'links', 'self'),
        );
    }

    #[Test]
    public function anAbsoluteServerUrlGivesAbsoluteExamples(): void
    {
        $document = $this->project(serverUrl: 'https://music.example/api/v5/');

        self::assertSame('https://music.example/api/v5/records/alb-1', $this->at($this->responseExample($document, '/records/{id}', 'get'), 'data', 'links', 'self', 'href'));
    }

    #[Test]
    public function noIdExampleGivesTheSharedLinkObjectExampleTheDefaultId(): void
    {
        $document = $this->project(serverUrl: null, idExample: null);

        self::assertSame('/records/1', $this->at($this->schema($document, 'LinkObject'), 'example', 'href'));
    }

    #[Test]
    public function withoutADocumentUrlNoLinkIsDescribed(): void
    {
        $document = $this->project(documentUrl: null);

        self::assertSame(['self' => ['href' => '/api/v5/records/alb-1'], 'publish' => ['href' => '/api/v5/records/alb-1/-actions/publish']], $this->at($this->responseExample($document, '/records/{id}', 'get'), 'data', 'links'));
        self::assertSame(['self'], \array_keys($this->arrAt($this->schema($document, 'DocumentLinks'), 'properties')));
        self::assertSame(['self', 'related', ...self::PAGES], \array_keys($this->arrAt($this->schema($document, 'RelationshipDocumentLinks'), 'properties')));
    }

    #[Test]
    public function anOperationTheDocumentDoesNotCarryIsNotDescribed(): void
    {
        // Without FetchOne the resource path carries no GET to point at.
        $document = $this->project(operations: [\haddowg\JsonApi\OpenApi\Metadata\OperationType::FetchCollection]);

        self::assertSame(['href' => '/api/v5/records/alb-1'], $this->at($this->responseExample($document, '/records', 'get'), 'data', 0, 'links', 'self'));
    }

    #[Test]
    public function theOptOutKeepsTheStringOrObjectLinkAndStringExamples(): void
    {
        $document = $this->project(linkObjects: false);

        self::assertArrayNotHasKey('RenderedLink', $this->arrAt($document, 'components', 'schemas'));

        self::assertSame('#/components/schemas/LinkObject', $this->at($this->schema($document, 'ResourceLinks'), 'properties', 'self', '$ref'));
        self::assertSame('#/components/schemas/LinkObject', $this->at($this->schema($document, 'RelationshipDocumentLinks'), 'properties', 'next', '$ref'));
        self::assertSame('#/components/schemas/LinkObject', $this->at($this->schema($document, 'AlbumsResourceLinks'), 'allOf', 1, 'properties', 'publish', '$ref'));
        self::assertSame('/api/v5/records/alb-1', $this->at($this->schema($document, 'LinkObject'), 'example'));
        self::assertStringContainsString('absolute to the request host', $this->str($this->schema($document, 'LinkObject'), 'description'));
        self::assertSame('/api/v5/records/alb-1', $this->at($this->responseExample($document, '/records/{id}', 'get'), 'data', 'links', 'self'));
    }

    #[Test]
    public function theSharedLinkObjectExampleIsTheFirstTypesResourceLink(): void
    {
        self::assertSame(
            ['href' => '/api/v5/records/alb-1', 'describedby' => self::DOCS . '#/paths/~1records~1%7Bid%7D/get'],
            $this->at($this->schema($this->project(), 'LinkObject'), 'example'),
        );
        self::assertSame(['href' => '/api/v5/records/alb-1'], $this->at($this->schema($this->project(documentUrl: null), 'LinkObject'), 'example'));

        $empty = new FakeServerMetadata(title: 'Music', version: '1', types: [], servers: [new Server('/api/v5')]);
        self::assertSame(['href' => '/api/v5/'], $this->at($this->schema((new OpenApiProjector())->project($empty)->toArray(), 'LinkObject'), 'example'));
    }

    #[Test]
    public function linkObjectsAreTheDefaultForMetadataThatDeclaresNothing(): void
    {
        $server = new FakeServerMetadata(
            title: 'Music',
            version: '1',
            types: [$this->albums('alb-1'), FakeTypeMetadata::resource('tracks', [Id::make()->build()]), FakeTypeMetadata::resource('artists', [Id::make()->build()])],
            servers: [new Server('/api/v5')],
        );
        $document = (new OpenApiProjector())->project($server)->toArray();

        self::assertArrayHasKey('RenderedLink', $this->arrAt($document, 'components', 'schemas'));
        self::assertSame('#/components/schemas/RenderedLink', $this->at($this->schema($document, 'ResourceLinks'), 'properties', 'self', '$ref'));
    }

    #[Test]
    public function theLinkObjectShapeCarriesDescribedbyAndHreflang(): void
    {
        $object = $this->arrAt($this->schema($this->project(), 'LinkObject'), 'anyOf', 1);

        self::assertArrayHasKey('describedby', $this->arrAt($object, 'properties'));
        self::assertSame(
            [['type' => 'string'], ['type' => 'array', 'items' => ['type' => 'string']]],
            $this->at($object, 'properties', 'hreflang', 'anyOf'),
        );
        self::assertSame(['href'], $this->at($this->schema($this->project(), 'RenderedLink'), 'required'));
    }

    #[Test]
    public function theBaselineContractCoversTheSharedLinks(): void
    {
        self::assertGreaterThanOrEqual(18, GeneratorContract::BASELINE);
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<array-key, mixed>
     */
    private function responseExample(array $document, string $path, string $method): array
    {
        return $this->arrAt($document, 'paths', $path, $method, 'responses', '200', 'content', 'application/vnd.api+json', 'example');
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private function str(array $value, int|string ...$keys): string
    {
        $leaf = $this->at($value, ...$keys);
        self::assertIsString($leaf);

        return $leaf;
    }

    /**
     * @param list<\haddowg\JsonApi\OpenApi\Metadata\OperationType>|null $operations
     *
     * @return array<string, mixed>
     */
    private function project(
        ?string $serverUrl = '/api/v5',
        ?string $documentUrl = self::DOCS,
        bool $linkObjects = true,
        ?string $idExample = 'alb-1',
        ?array $operations = null,
    ): array {
        $server = new FakeServerMetadata(
            title: 'Music',
            version: '1',
            types: [$this->albums($idExample, $operations), FakeTypeMetadata::resource('tracks', [Id::make()->example('trk-1')->build()]), FakeTypeMetadata::resource('artists', [Id::make()->example('art-1')->build()])],
            servers: $serverUrl === null ? [] : [new Server($serverUrl)],
        );

        return $this->decode((new OpenApiProjector())->project($this->declaring($server, $linkObjects, $documentUrl))->toJsonString());
    }

    private function declaring(ServerMetadataInterface $server, bool $linkObjects, ?string $documentUrl): ServerMetadataInterface
    {
        return new LinkDeclaringServerMetadata($server, $linkObjects, $documentUrl);
    }

    /**
     * @param list<\haddowg\JsonApi\OpenApi\Metadata\OperationType>|null $operations
     */
    private function albums(?string $idExample, ?array $operations = null): FakeTypeMetadata
    {
        $id = Id::make();
        if ($idExample !== null) {
            $id = $id->example($idExample);
        }

        return FakeTypeMetadata::resource(
            type: 'albums',
            fields: [$id->build(), Str::make('title')->example('Blue Train')->build()],
            relations: [
                new FakeRelationMetadata('artist', ['artists'], false, relationshipEndpoint: false),
                new FakeRelationMetadata('tracks', ['tracks'], true, pageSchema: Schema::ofType('object')),
            ],
            uriType: 'records',
            operations: $operations,
            actions: [
                new FakeActionMetadata('publish', ['POST'], ActionScope::Resource, linkName: 'publish'),
                new FakeActionMetadata('archive', ['POST'], ActionScope::Resource),
                new FakeActionMetadata('import', ['POST'], ActionScope::Collection, ActionInputMode::Raw, linkName: 'import'),
            ],
        );
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function schema(array $document, string $name): array
    {
        $schemas = $this->arrAt($document, 'components', 'schemas');
        $schema = $schemas[$name] ?? null;
        self::assertIsArray($schema, $name . ' is missing');

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        $decoded = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private function at(array $value, int|string ...$keys): mixed
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
    private function arrAt(array $value, int|string ...$keys): array
    {
        $leaf = $this->at($value, ...$keys);
        self::assertIsArray($leaf);

        return $leaf;
    }
}
