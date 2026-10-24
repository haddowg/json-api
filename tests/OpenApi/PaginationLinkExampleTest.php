<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\LinkSchemaProjector;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\OpenApi\Server;
use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Pagination\MultiPaginator;
use haddowg\JsonApi\Pagination\OffsetPaginator;
use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\LinkDeclaringServerMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A pagination link's example carries the `page[…]` members the paginator in force
 * writes into its links, under its configured keys, encoded as a rendered link is.
 */
#[CoversClass(LinkSchemaProjector::class)]
final class PaginationLinkExampleTest extends TestCase
{
    #[Test]
    public function aPagePaginatorUsesItsKeysAndDefaultSize(): void
    {
        $links = $this->collectionLinks(PagePaginator::make()->withPageKey('p')->withPerPageKey('per')->withDefaultPerPage(25)->describePageSchema());

        self::assertSame('/api/albums?page%5Bp%5D=1&page%5Bper%5D=25', $this->href($links, 'first'));
        self::assertSame('/api/albums?page%5Bp%5D=1&page%5Bper%5D=25', $this->href($links, 'prev'));
        self::assertSame('/api/albums?page%5Bp%5D=3&page%5Bper%5D=25', $this->href($links, 'next'));
        self::assertSame('/api/albums?page%5Bp%5D=5&page%5Bper%5D=25', $this->href($links, 'last'));
        self::assertSame('/api/albums', $this->href($links, 'self'));
    }

    #[Test]
    public function anOffsetPaginatorStepsByItsLimit(): void
    {
        $links = $this->collectionLinks(OffsetPaginator::make()->withDefaultLimit(20)->describePageSchema());

        self::assertSame('/api/albums?page%5Boffset%5D=0&page%5Blimit%5D=20', $this->href($links, 'first'));
        self::assertSame('/api/albums?page%5Boffset%5D=40&page%5Blimit%5D=20', $this->href($links, 'next'));
        self::assertSame('/api/albums?page%5Boffset%5D=80&page%5Blimit%5D=20', $this->href($links, 'last'));
    }

    #[Test]
    public function aCursorPaginatorShowsCursorsAndNoLastExample(): void
    {
        $links = $this->collectionLinks(CursorPaginator::make()->withDefaultSize(30)->describePageSchema());

        self::assertSame('/api/albums?page%5Bsize%5D=30', $this->href($links, 'first'));
        self::assertStringStartsWith('/api/albums?page%5Bafter%5D=', $this->href($links, 'next'));
        self::assertStringEndsWith('&page%5Bsize%5D=30', $this->href($links, 'next'));
        self::assertStringStartsWith('/api/albums?page%5Bbefore%5D=', $this->href($links, 'prev'));
        // A cursor page never renders `last`, so its member keeps the bare link.
        self::assertSame('/api/albums', $this->href($links, 'last'));
    }

    #[Test]
    public function aMenuUsesItsDefaultStrategyWithoutAKind(): void
    {
        $links = $this->collectionLinks((new MultiPaginator(OffsetPaginator::make()->withDefaultLimit(5), PagePaginator::make()))->describePageSchema());

        self::assertSame('/api/albums?page%5Boffset%5D=10&page%5Blimit%5D=5', $this->href($links, 'next'));
    }

    #[Test]
    public function aToManyRelationshipUsesItsOwnPaginator(): void
    {
        $document = $this->project(PagePaginator::make()->describePageSchema(), OffsetPaginator::make()->withDefaultLimit(3)->describePageSchema());
        $links = $this->component($document, 'AlbumsTracksRelationshipLinks');

        self::assertSame('/api/albums/1/relationships/tracks?page%5Boffset%5D=6&page%5Blimit%5D=3', $this->href($links, 'next'));
    }

    #[Test]
    public function aLinksObjectCarriesItsMembersAsOneExample(): void
    {
        $links = $this->collectionLinks(CursorPaginator::make()->withDefaultSize(30)->describePageSchema());

        $example = $links['example'] ?? null;
        self::assertIsArray($example);
        // A cursor page never renders `last`, so the whole-object example leaves it out.
        self::assertSame(['self', 'first', 'prev', 'next'], \array_keys($example));
        self::assertSame($this->href($links, 'next'), $this->exampleHref($example, 'next'));
    }

    #[Test]
    public function theRelationshipDocumentListsTheRelationshipsLinksAndItsPages(): void
    {
        $document = $this->project(PagePaginator::make()->describePageSchema(), PagePaginator::make()->withDefaultPerPage(4)->describePageSchema(), '/api/docs.json');
        $links = $this->component($document, 'AlbumsTracksRelationshipDocumentLinks');

        $example = $links['example'] ?? null;
        self::assertIsArray($example);
        self::assertSame(['self', 'related', 'first', 'prev', 'next', 'last', 'describedby'], \array_keys($example));
        self::assertSame(
            ['href' => '/api/albums/1/relationships/tracks', 'describedby' => '/api/docs.json#/paths/~1albums~1%7Bid%7D~1relationships~1tracks/get'],
            $example['self'],
        );
        self::assertSame(
            ['href' => '/api/albums/1/tracks', 'describedby' => '/api/docs.json#/paths/~1albums~1%7Bid%7D~1tracks/get'],
            $example['related'],
        );
        self::assertSame('/api/albums/1/relationships/tracks?page%5Bnumber%5D=3&page%5Bsize%5D=4', $this->exampleHref($example, 'next'));
        self::assertSame(['href' => '/api/docs.json#/paths/~1albums~1%7Bid%7D~1relationships~1tracks/get'], $example['describedby']);
    }

    #[Test]
    public function theRelatedDocumentListsTheRelatedUrlAndItsPages(): void
    {
        $document = $this->project(PagePaginator::make()->describePageSchema(), OffsetPaginator::make()->withDefaultLimit(3)->describePageSchema(), '/api/docs.json');
        $links = $this->component($document, 'AlbumsTracksRelatedDocumentLinks');

        $example = $links['example'] ?? null;
        self::assertIsArray($example);
        self::assertSame(['self', 'describedby', 'first', 'prev', 'next', 'last'], \array_keys($example));
        self::assertSame(
            ['href' => '/api/albums/1/tracks', 'describedby' => '/api/docs.json#/paths/~1albums~1%7Bid%7D~1tracks/get'],
            $example['self'],
        );
        self::assertSame('/api/albums/1/tracks?page%5Boffset%5D=6&page%5Blimit%5D=3', $this->exampleHref($example, 'next'));

        $collection = $this->component($document, 'AlbumsTracksRelatedCollection');
        self::assertSame(['$ref' => '#/components/schemas/AlbumsTracksRelatedDocumentLinks'], $this->member($collection, 'links'));
    }

    /**
     * @param array<array-key, mixed> $example
     */
    private function exampleHref(array $example, string $member): string
    {
        $link = $example[$member] ?? null;
        self::assertIsArray($link);
        self::assertIsString($link['href'] ?? null);

        return $link['href'];
    }

    /**
     * @param array<array-key, mixed> $schema
     */
    private function member(array $schema, string $name): mixed
    {
        $properties = $schema['properties'] ?? null;
        self::assertIsArray($properties);

        return $properties[$name] ?? null;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function collectionLinks(Schema $pageSchema): array
    {
        return $this->component($this->project($pageSchema), 'AlbumsCollectionLinks');
    }

    /**
     * @return array<array-key, mixed>
     */
    private function project(Schema $pageSchema, ?Schema $tracksPageSchema = null, ?string $documentUrl = null): array
    {
        $albums = FakeTypeMetadata::resource(
            type: 'albums',
            fields: [Id::make()->build()],
            relations: [new FakeRelationMetadata('tracks', ['tracks'], true, pageSchema: $tracksPageSchema)],
            pageSchema: $pageSchema,
        );
        $server = new FakeServerMetadata(
            title: 'Music',
            version: '1',
            types: [$albums, FakeTypeMetadata::resource('tracks', [Id::make()->build()])],
            servers: [new Server('/api')],
        );

        $json = (new OpenApiProjector())->project(new LinkDeclaringServerMetadata($server, true, $documentUrl))->toJsonString();
        $decoded = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array<array-key, mixed> $document
     *
     * @return array<array-key, mixed>
     */
    private function component(array $document, string $name): array
    {
        $components = $document['components'] ?? null;
        self::assertIsArray($components);
        $schemas = $components['schemas'] ?? null;
        self::assertIsArray($schemas);
        $component = $schemas[$name] ?? null;
        self::assertIsArray($component);

        return $component;
    }

    /**
     * @param array<array-key, mixed> $links
     */
    private function href(array $links, string $member): string
    {
        $properties = $links['properties'] ?? null;
        self::assertIsArray($properties);
        $link = $properties[$member] ?? null;
        self::assertIsArray($link);
        $example = $link['example'] ?? ['href' => null];
        self::assertIsArray($example);
        self::assertIsString($example['href'] ?? null);

        return $example['href'];
    }
}
