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
 * Each links context projects its own component listing the members it renders, as the
 * link objects the server renders, with examples built from the document's server URL
 * and a `describedby` into the document when the server names its URL.
 */
#[CoversClass(LinkSchemaProjector::class)]
#[CoversClass(OpenApiProjector::class)]
final class LinkProjectionTest extends TestCase
{
    private const DOCS = '/api/v5/docs.json';

    #[Test]
    public function aResourceObjectListsItsSelfAndEachActionLink(): void
    {
        $links = $this->schema($this->project(), 'AlbumsResourceLinks');

        self::assertSame(
            [
                'href' => '/api/v5/records/alb-1',
                'describedby' => self::DOCS . '#/paths/~1records~1%7Bid%7D/get',
            ],
            $this->at($links, 'properties', 'self', 'example'),
        );
        // The example sits beside the link shape, so the `$ref` moves into an `allOf`
        // where tooling that ignores a `$ref`'s siblings still shows it.
        self::assertSame('#/components/schemas/RenderedLink', $this->at($links, 'properties', 'self', 'allOf', 0, '$ref'));
        self::assertArrayNotHasKey('$ref', $this->arrAt($links, 'properties', 'self'));
        self::assertSame(
            [
                'href' => '/api/v5/records/alb-1/-actions/publish',
                'describedby' => self::DOCS . '#/paths/~1records~1%7Bid%7D~1-actions~1publish/post',
            ],
            $this->at($links, 'properties', 'publish', 'example'),
        );
        // A collection action has no resource to hang a link off, and an action that
        // declares no link name renders none.
        self::assertSame(['self', 'publish'], \array_keys($this->arrAt($links, 'properties')));
        self::assertSame(['$ref' => '#/components/schemas/LinkObject'], $this->at($links, 'additionalProperties'));
        $description = $this->at($links, 'properties', 'self', 'description');
        self::assertIsString($description);
        self::assertStringContainsString('absolute to the request host', $description);

        $resource = $this->schema($this->project(), 'AlbumsResource');
        self::assertSame('#/components/schemas/AlbumsResourceLinks', $this->at($resource, 'properties', 'links', '$ref'));
    }

    #[Test]
    public function theDocumentsListTheirSelfAndTopLevelDescribedby(): void
    {
        $document = $this->project();

        $single = $this->schema($document, 'AlbumsDocumentLinks');
        self::assertSame(['self', 'describedby'], \array_keys($this->arrAt($single, 'properties')));
        self::assertSame(['href' => self::DOCS . '#/paths/~1records~1%7Bid%7D/get'], $this->at($single, 'properties', 'describedby', 'example'));
        self::assertSame('#/components/schemas/AlbumsDocumentLinks', $this->at($this->schema($document, 'AlbumsDocument'), 'properties', 'links', '$ref'));

        $collection = $this->schema($document, 'AlbumsCollectionLinks');
        self::assertSame(['self', 'describedby', 'first', 'prev', 'next', 'last'], \array_keys($this->arrAt($collection, 'properties')));
        self::assertSame(
            ['href' => '/api/v5/records?page%5Bnumber%5D=3&page%5Bsize%5D=15', 'describedby' => self::DOCS . '#/paths/~1records/get'],
            $this->at($collection, 'properties', 'next', 'example'),
        );
        self::assertSame('#/components/schemas/AlbumsCollectionLinks', $this->at($this->schema($document, 'AlbumsCollection'), 'properties', 'links', '$ref'));
    }

    #[Test]
    public function aRelationshipObjectListsTheLinksItsExposedEndpointsAllow(): void
    {
        $document = $this->project();

        $tracks = $this->schema($document, 'AlbumsTracksRelationshipLinks');
        self::assertSame(['self', 'related', 'first', 'prev', 'next', 'last'], \array_keys($this->arrAt($tracks, 'properties')));
        self::assertSame(
            [
                'href' => '/api/v5/records/alb-1/relationships/tracks',
                'describedby' => self::DOCS . '#/paths/~1records~1%7Bid%7D~1relationships~1tracks/get',
            ],
            $this->at($tracks, 'properties', 'self', 'example'),
        );
        self::assertSame(
            ['href' => '/api/v5/records/alb-1/tracks', 'describedby' => self::DOCS . '#/paths/~1records~1%7Bid%7D~1tracks/get'],
            $this->at($tracks, 'properties', 'related', 'example'),
        );
        self::assertSame('#/components/schemas/AlbumsTracksRelationshipLinks', $this->at($this->schema($document, 'AlbumsTracksRelationship'), 'properties', 'links', '$ref'));

        // The artist relationship endpoint isn't exposed, so there's no `self` to list,
        // and a to-one never paginates.
        self::assertSame(['related'], \array_keys($this->arrAt($this->schema($document, 'AlbumsArtistRelationshipLinks'), 'properties')));
    }

    #[Test]
    public function anAbsoluteServerUrlGivesAbsoluteExamples(): void
    {
        $links = $this->schema($this->project(serverUrl: 'https://music.example/api/v5/'), 'AlbumsResourceLinks');

        self::assertSame('https://music.example/api/v5/records/alb-1', $this->at($links, 'properties', 'self', 'example', 'href'));
    }

    #[Test]
    public function noServerUrlGivesHostRelativeExamplesAndTheDefaultId(): void
    {
        $links = $this->schema($this->project(serverUrl: null, idExample: null), 'AlbumsResourceLinks');

        self::assertSame('/records/1', $this->at($links, 'properties', 'self', 'example', 'href'));
    }

    #[Test]
    public function withoutADocumentUrlNoLinkIsDescribed(): void
    {
        $document = $this->project(documentUrl: null);

        self::assertSame(['href' => '/api/v5/records/alb-1'], $this->at($this->schema($document, 'AlbumsResourceLinks'), 'properties', 'self', 'example'));
        self::assertSame(['self'], \array_keys($this->arrAt($this->schema($document, 'AlbumsDocumentLinks'), 'properties')));
    }

    #[Test]
    public function anOperationTheDocumentDoesNotCarryIsNotDescribed(): void
    {
        // Without FetchOne the resource path carries no GET to point at.
        $document = $this->project(operations: [\haddowg\JsonApi\OpenApi\Metadata\OperationType::FetchCollection]);

        self::assertSame(['href' => '/api/v5/records/alb-1'], $this->at($this->schema($document, 'AlbumsResourceLinks'), 'properties', 'self', 'example'));
    }

    #[Test]
    public function theOptOutKeepsTheStringOrObjectLinkAndStringExamples(): void
    {
        $document = $this->project(linkObjects: false);

        self::assertArrayNotHasKey('RenderedLink', $this->arrAt($document, 'components', 'schemas'));

        $self = $this->arrAt($this->schema($document, 'AlbumsResourceLinks'), 'properties', 'self');
        self::assertSame('#/components/schemas/LinkObject', $this->at($self, 'allOf', 0, '$ref'));
        self::assertSame('/api/v5/records/alb-1', $this->at($self, 'example'));

        self::assertSame('#/components/schemas/LinkObject', $this->at($this->schema($document, 'AlbumsTracksRelationshipDocumentLinks'), 'properties', 'next', 'allOf', 0, '$ref'));
        self::assertSame('/api/v5/', $this->at($this->schema($document, 'LinkObject'), 'example'));
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
        self::assertSame(['href' => '/api/v5/records/alb-1'], $this->at($this->schema($document, 'AlbumsResourceLinks'), 'properties', 'self', 'example'));
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
    public function theContractIsNine(): void
    {
        self::assertSame(9, GeneratorContract::CONTRACT);
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
            types: [$this->albums($idExample, $operations), FakeTypeMetadata::resource('tracks', [Id::make()->build()]), FakeTypeMetadata::resource('artists', [Id::make()->build()])],
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
            fields: [$id->build(), Str::make('title')->build()],
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
