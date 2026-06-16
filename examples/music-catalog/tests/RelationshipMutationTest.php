<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Examples\MusicCatalog\Tests;

use haddowg\JsonApi\Testing\AssertsSpecCompliance;
use haddowg\JsonApi\Testing\JsonApiDocument;
use haddowg\JsonApi\Testing\JsonApiErrors;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/**
 * The runnable backing for `docs/relationship-mutation.md`.
 *
 * The three relationship-endpoint verbs map to a
 * {@see \haddowg\JsonApi\Resource\Field\Mode} inside core's
 * `AbstractResource::hydrateRelationship()`: `PATCH` → Replace, `POST` → Add,
 * `DELETE` → Remove. This suite drives all three against an **open** to-many
 * (`albums→tracks`, no mutability flags) and verifies the resulting linkage, then
 * exercises the gates:
 *
 *  - `cannotReplace()` on `tracks→playlists` → `PATCH …/relationships/playlists`
 *    is `403` {@see \haddowg\JsonApi\Exception\FullReplacementProhibited} (add /
 *    remove still allowed);
 *  - a `POST` / `DELETE` to a **to-one** relationship endpoint (`albums→artist`)
 *    is `400` {@see \haddowg\JsonApi\Exception\RelationshipTypeInappropriate}
 *    (add / remove are to-many operations);
 *  - relationships embedded in a whole-resource write apply through the same seam.
 *
 * Each test method runs against a freshly seeded store, so the mutations are
 * isolated. Album 1 is seeded with tracks 1, 2, 3.
 */
#[Group('spec:updating-relationships')]
final class RelationshipMutationTest extends MusicCatalogTestCase
{
    use AssertsSpecCompliance;

    #[Test]
    public function patchReplacesTheWholeToManyLinkage(): void
    {
        // PATCH → Replace: album 1's three tracks become just track 4.
        $response = $this->patch('/albums/1/relationships/tracks', [
            'data' => [
                ['type' => 'tracks', 'id' => '4'],
            ],
        ]);

        self::assertSame(200, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);

        self::assertSame(['4'], $this->linkageIds($response), 'replace swaps the entire set');
    }

    #[Test]
    public function postAddsToTheToManyLinkageWithoutDuplicating(): void
    {
        // POST → Add: track 4 joins album 1's existing {1,2,3}; re-adding an
        // already-present member does not duplicate it (a deduplicated id set).
        $response = $this->post('/albums/1/relationships/tracks', [
            'data' => [
                ['type' => 'tracks', 'id' => '4'],
                ['type' => 'tracks', 'id' => '1'],
            ],
        ]);

        self::assertSame(200, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);

        self::assertSame(['1', '2', '3', '4'], $this->linkageIds($response));
    }

    #[Test]
    public function deleteRemovesFromTheToManyLinkage(): void
    {
        // DELETE → Remove: track 1 leaves album 1's {1,2,3}.
        $response = $this->delete('/albums/1/relationships/tracks', [
            'data' => [
                ['type' => 'tracks', 'id' => '1'],
            ],
        ]);

        self::assertSame(200, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);

        self::assertSame(['2', '3'], $this->linkageIds($response));
    }

    #[Test]
    public function aMutatedLinkagePersistsAndIsReadableBack(): void
    {
        $this->patch('/albums/1/relationships/tracks', [
            'data' => [
                ['type' => 'tracks', 'id' => '4'],
                ['type' => 'tracks', 'id' => '2'],
            ],
        ]);

        // Re-read the linkage through the relationship endpoint: the mutation stuck.
        $read = $this->get('/albums/1/relationships/tracks');

        self::assertSame(200, $read->getStatusCode());
        self::assertSame(['4', '2'], $this->linkageIds($read));
    }

    #[Test]
    public function replaceIsProhibitedOnACannotReplaceRelation(): void
    {
        // tracks→playlists declares cannotReplace(): a PATCH to the relationship
        // endpoint is rejected with 403 FullReplacementProhibited.
        $response = $this->patch('/tracks/1/relationships/playlists', [
            'data' => [
                ['type' => 'playlists', 'id' => '00000000-0000-4000-8000-000000000001'],
            ],
        ]);

        self::assertSame(403, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);

        JsonApiErrors::of($response)
            ->assertHasError(status: '403', code: 'FULL_REPLACEMENT_PROHIBITED');
    }

    #[Test]
    public function addIsStillAllowedOnACannotReplaceRelation(): void
    {
        // cannotReplace() gates only Replace — POST (Add) to the same relation works.
        $response = $this->post('/tracks/3/relationships/playlists', [
            'data' => [
                ['type' => 'playlists', 'id' => '00000000-0000-4000-8000-000000000001'],
            ],
        ]);

        self::assertSame(200, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);

        self::assertContains('00000000-0000-4000-8000-000000000001', $this->linkageIds($response));
    }

    #[Test]
    public function addToAToOneRelationshipEndpointIsACardinalityError(): void
    {
        // POST → Add against a to-one (albums→artist) is a 400
        // RelationshipTypeInappropriate: add/remove are to-many operations.
        $response = $this->post('/albums/1/relationships/artist', [
            'data' => ['type' => 'artists', 'id' => '2'],
        ]);

        self::assertSame(400, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);
    }

    #[Test]
    public function removeFromAToOneRelationshipEndpointIsACardinalityError(): void
    {
        $response = $this->delete('/albums/1/relationships/artist', [
            'data' => ['type' => 'artists', 'id' => '1'],
        ]);

        self::assertSame(400, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);
    }

    #[Test]
    public function patchReplacesAToOneRelationship(): void
    {
        // PATCH (Replace) IS allowed on a to-one: re-point album 1 at artist 2.
        $response = $this->patch('/albums/1/relationships/artist', [
            'data' => ['type' => 'artists', 'id' => '2'],
        ]);

        self::assertSame(200, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);

        $data = JsonApiDocument::of($response)->data();
        self::assertIsArray($data);
        self::assertSame('artists', $data['type'] ?? null);
        self::assertSame('2', $data['id'] ?? null);
    }

    #[Test]
    public function aRelationshipEmbeddedInAWholeResourceWriteIsApplied(): void
    {
        // The same linkage-write seam runs inside a PATCH /albums/{id}: the
        // `artist` relationship in the document re-points the album.
        $response = $this->patch('/albums/1', [
            'data' => [
                'type' => 'albums',
                'id' => '1',
                'relationships' => [
                    'artist' => [
                        'data' => ['type' => 'artists', 'id' => '2'],
                    ],
                ],
            ],
        ]);

        self::assertSame(200, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);

        JsonApiDocument::of($response)
            ->assertHasRelationship('artist', 'artists', '2');
    }

    /**
     * The list of resource-identifier ids in a linkage document, in order.
     *
     * @return list<string>
     */
    private function linkageIds(ResponseInterface $response): array
    {
        $data = JsonApiDocument::of($response)->data();
        self::assertIsArray($data);

        $ids = [];
        foreach ($data as $identifier) {
            self::assertIsArray($identifier);
            $id = $identifier['id'] ?? null;
            self::assertIsString($id);
            $ids[] = $id;
        }

        return $ids;
    }

    private function get(string $path): ResponseInterface
    {
        return $this->server()->handle(new ServerRequest('GET', 'https://music.example' . $path, [
            'Accept' => 'application/vnd.api+json',
        ]));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function patch(string $path, array $body): ResponseInterface
    {
        return $this->write('PATCH', $path, $body);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(string $path, array $body): ResponseInterface
    {
        return $this->write('POST', $path, $body);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function delete(string $path, array $body): ResponseInterface
    {
        return $this->write('DELETE', $path, $body);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function write(string $method, string $path, array $body): ResponseInterface
    {
        return $this->server()->handle(new ServerRequest(
            $method,
            'https://music.example' . $path,
            [
                'Accept' => 'application/vnd.api+json',
                'Content-Type' => 'application/vnd.api+json',
            ],
            (string) \json_encode($body),
        ));
    }
}
