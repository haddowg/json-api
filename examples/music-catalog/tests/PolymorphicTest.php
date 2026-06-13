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
 * The runnable backing for the polymorphic sections of
 * `docs/related-endpoints.md` and `docs/serializers.md`.
 *
 * Two polymorphic relations are exercised end-to-end:
 *
 * - **MorphTo** (`favorites→favoritable`): the to-one member's serializer is
 *   resolved at runtime from the related object's own type, so the same endpoint
 *   renders a track, an album, or an artist depending on the favorite.
 * - **MorphToMany** (`libraries→items`): a mixed collection rendered through a
 *   {@see \haddowg\JsonApi\Serializer\PolymorphicSerializer} decorator. The
 *   in-memory provider supports the mixed read; the polymorphic to-many carries no
 *   shared filter/sort vocabulary (those `400`), but `page` slices it.
 */
#[Group('spec:fetching-relationships')]
final class PolymorphicTest extends MusicCatalogTestCase
{
    use AssertsSpecCompliance;

    #[Test]
    public function morphToRelatedReadResolvesATrackMember(): void
    {
        // favorite 1 favourites track 2 (Paranoid Android).
        $response = $this->get('/favorites/1/favoritable');

        self::assertSame(200, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);

        JsonApiDocument::of($response)
            ->assertHasType('tracks')
            ->assertHasId('2')
            ->assertHasAttribute('title', 'Paranoid Android');
    }

    #[Test]
    public function morphToRelatedReadResolvesAnAlbumMember(): void
    {
        // favorite 2 favourites album 1 (OK Computer) — the SAME endpoint shape now
        // resolves a different serializer from the related object's type.
        $response = $this->get('/favorites/2/favoritable');

        self::assertSame(200, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);

        JsonApiDocument::of($response)
            ->assertHasType('albums')
            ->assertHasId('1')
            ->assertHasAttribute('title', 'OK Computer');
    }

    #[Test]
    public function morphToRelatedReadResolvesAnArtistMember(): void
    {
        // favorite 3 favourites artist 2 (Portishead).
        $response = $this->get('/favorites/3/favoritable');

        self::assertSame(200, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);

        JsonApiDocument::of($response)
            ->assertHasType('artists')
            ->assertHasId('2')
            ->assertHasAttribute('name', 'Portishead');
    }

    #[Test]
    public function morphToRelationshipReadResolvesThePolymorphicLinkageType(): void
    {
        // GET /favorites/2/relationships/favoritable → an album identifier.
        $response = $this->get('/favorites/2/relationships/favoritable');

        self::assertSame(200, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);

        $data = $this->single($response);
        self::assertSame('albums', $data['type'] ?? null);
        self::assertSame('1', $data['id'] ?? null);
    }

    #[Test]
    public function morphToManyRelatedReadRendersMixedMembers(): void
    {
        // library 1 holds a track, an album, and an artist — rendered through the
        // PolymorphicSerializer so each member carries its own type.
        $response = $this->get('/libraries/1/items');

        self::assertSame(200, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);

        $data = $this->collection($response);
        self::assertCount(3, $data);

        $types = [];
        foreach ($data as $row) {
            $type = $row['type'] ?? null;
            self::assertIsString($type);
            $types[] = $type;
        }
        \sort($types);
        self::assertSame(['albums', 'artists', 'tracks'], $types);
    }

    #[Test]
    public function morphToManyRelationshipReadRendersMixedLinkage(): void
    {
        $response = $this->get('/libraries/1/relationships/items');

        self::assertSame(200, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);

        $data = $this->collection($response);
        self::assertCount(3, $data);
        foreach ($data as $identifier) {
            self::assertArrayNotHasKey('attributes', $identifier, 'linkage carries no attributes');
            self::assertContains($identifier['type'] ?? null, ['tracks', 'albums', 'artists']);
        }
    }

    #[Test]
    public function aPolymorphicToManyPaginates(): void
    {
        // page slices the mixed collection even though filter/sort cannot.
        $response = $this->get('/libraries/1/items?page[number]=1&page[size]=2');

        self::assertSame(200, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);

        self::assertCount(2, $this->collection($response), 'page[size]=2 slices the mixed three-member collection');
    }

    #[Test]
    public function aFilterOnAPolymorphicToManyIsRejected(): void
    {
        // A polymorphic to-many carries no shared filter vocabulary → 400.
        $response = $this->get('/libraries/1/items?filter[title]=anything');

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function aSortOnAPolymorphicToManyIsRejected(): void
    {
        $response = $this->get('/libraries/1/items?sort=title');

        self::assertSame(400, $response->getStatusCode());
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

    private function get(string $path): ResponseInterface
    {
        return $this->server()->handle(new ServerRequest('GET', 'https://music.example' . $path, [
            'Accept' => 'application/vnd.api+json',
        ]));
    }
}
