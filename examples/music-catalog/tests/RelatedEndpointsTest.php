<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Examples\MusicCatalog\Tests;

use haddowg\JsonApi\Testing\AssertsSpecCompliance;
use haddowg\JsonApi\Testing\JsonApiDocument;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/**
 * The runnable backing for `docs/related-endpoints.md`.
 *
 * Every relation exposes two read endpoints: the related-resource read
 * (`GET /{type}/{id}/{rel}` → full related resource(s)) and the relationship
 * linkage read (`GET /{type}/{id}/relationships/{rel}` → identifiers only). This
 * suite walks each relation shape — `belongsTo`, `hasOne`, `hasMany`,
 * `belongsToMany` — for both endpoints, plus the per-relation paginated related
 * collection and the empty-to-one `data: null`. The polymorphic
 * (`MorphTo`/`MorphToMany`) cases live in {@see PolymorphicTest}.
 */
#[Group('spec:fetching-relationships')]
final class RelatedEndpointsTest extends MusicCatalogTestCase
{
    use AssertsSpecCompliance;

    #[Test]
    public function relatedReadReturnsTheFullToOneResource(): void
    {
        // GET /albums/1/artist → the artist resource (album 1 belongs to artist 1).
        $response = $this->get('/albums/1/artist');

        self::assertSame(200, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);

        JsonApiDocument::of($response)
            ->assertHasType('artists')
            ->assertHasId('1')
            ->assertHasAttribute('name', 'Radiohead');
    }

    #[Test]
    public function relationshipReadReturnsToOneLinkageOnly(): void
    {
        // GET /albums/1/relationships/artist → a single identifier, no attributes.
        $response = $this->get('/albums/1/relationships/artist');

        self::assertSame(200, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);

        $data = $this->single($response);
        self::assertSame('artists', $data['type'] ?? null);
        self::assertSame('1', $data['id'] ?? null);
        self::assertArrayNotHasKey('attributes', $data, 'a linkage read carries no attributes');
    }

    #[Test]
    public function relatedReadReturnsAToManyCollection(): void
    {
        // GET /albums/2/tracks → the single track of album 2 (Mysterons).
        $response = $this->get('/albums/2/tracks');

        self::assertSame(200, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);

        $data = $this->collection($response);
        self::assertCount(1, $data);
        self::assertSame('tracks', $data[0]['type'] ?? null);
        self::assertSame('4', $data[0]['id'] ?? null);
    }

    #[Test]
    public function relationshipReadReturnsToManyLinkage(): void
    {
        // GET /albums/1/relationships/tracks → three identifiers, no attributes.
        $response = $this->get('/albums/1/relationships/tracks');

        self::assertSame(200, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);

        $data = $this->collection($response);
        self::assertCount(3, $data);
        foreach ($data as $identifier) {
            self::assertSame('tracks', $identifier['type'] ?? null);
            self::assertArrayNotHasKey('attributes', $identifier);
        }
    }

    #[Test]
    public function aPaginatedRelatedCollectionWindowsPerRelation(): void
    {
        // album→tracks declares paginate(perPage=2). Album 1 has three tracks, so
        // the related read yields a first page of two with next/last links and a
        // page meta total of three.
        $response = $this->get('/albums/1/tracks');

        self::assertSame(200, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);

        $doc = JsonApiDocument::of($response);
        self::assertCount(2, $this->collection($response), 'the per-relation paginator caps the first page at two');

        $meta = $doc->meta();
        self::assertArrayHasKey('page', $meta);
        $page = $meta['page'];
        self::assertIsArray($page);
        self::assertSame(3, $page['total'] ?? null);

        self::assertArrayHasKey('next', $doc->links());
    }

    #[Test]
    public function aPaginatedRelatedCollectionLinksScopeToTheRelatedUrl(): void
    {
        // The pagination self/next links target the related-collection URL, not a
        // primary collection.
        $response = $this->get('/albums/1/tracks');

        $links = JsonApiDocument::of($response)->links();
        self::assertArrayHasKey('next', $links);
        self::assertStringContainsString('/albums/1/tracks', $this->href($links['next']));
    }

    #[Test]
    public function hasOneRelatedReadReturnsTheRelatedResource(): void
    {
        // artist→featuredAlbum (HasOne). Radiohead's featured album is album 1.
        $response = $this->get('/artists/1/featuredAlbum');

        self::assertSame(200, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);

        JsonApiDocument::of($response)
            ->assertHasType('albums')
            ->assertHasId('1');
    }

    #[Test]
    public function anEmptyToOneRelatedReadRendersDataNull(): void
    {
        // Portishead (artist 2) has no featuredAlbum → data: null.
        $response = $this->get('/artists/2/featuredAlbum');

        self::assertSame(200, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);

        self::assertNull(JsonApiDocument::of($response)->data());
    }

    #[Test]
    public function belongsToManyRelatedReadReturnsTheRelatedCollection(): void
    {
        // track 1 (Airbag) belongs to one playlist (Morning Mix).
        $response = $this->get('/tracks/1/playlists');

        self::assertSame(200, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);

        $data = $this->collection($response);
        self::assertCount(1, $data);
        self::assertSame('playlists', $data[0]['type'] ?? null);
    }

    #[Test]
    public function compoundIncludeWorksOnARelatedEndpoint(): void
    {
        // GET /albums/1/artist?include=albums — the related artist plus its albums.
        $response = $this->get('/albums/1/artist?include=albums');

        self::assertSame(200, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);

        JsonApiDocument::of($response)
            ->assertHasType('artists')
            ->assertHasIncluded('albums');
    }

    /**
     * @return array<string, mixed>
     */
    private function single(ResponseInterface $response): array
    {
        $data = JsonApiDocument::of($response)->data();
        self::assertIsArray($data);

        return $data;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collection(ResponseInterface $response): array
    {
        $data = JsonApiDocument::of($response)->data();
        self::assertIsArray($data);

        $rows = [];
        foreach ($data as $row) {
            self::assertIsArray($row);
            $rows[] = $row;
        }

        return $rows;
    }

    private function href(mixed $link): string
    {
        if (\is_array($link)) {
            $href = $link['href'] ?? '';

            return \is_string($href) ? $href : '';
        }

        return \is_string($link) ? $link : '';
    }

    private function get(string $path): ResponseInterface
    {
        return $this->server()->handle(new ServerRequest('GET', 'https://music.example' . $path, [
            'Accept' => 'application/vnd.api+json',
        ]));
    }
}
