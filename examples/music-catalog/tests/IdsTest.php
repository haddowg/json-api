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
 * The runnable backing for `docs/ids.md`.
 *
 * Two id sources are contrasted end-to-end through the wired server:
 *
 *  - **App-generated**: `AlbumResource` declares `Id::make()->uuid()->generated()`,
 *    so an `albums` `POST` with no `id` member is given an app-minted UUID, and a
 *    client-supplied id on that type is *rejected* with
 *    {@see \haddowg\JsonApi\Exception\ClientGeneratedIdNotSupported} (`403`),
 *    because a default resource does not opt in to client ids.
 *  - **Client-generated**: `PlaylistResource` declares
 *    `Id::make()->uuid()->allowClientId()`, so a `POST` that carries its own UUID
 *    `id` is honoured and the created resource is readable at exactly that id.
 *
 * The implicit-id default (a resource that declares no `Id` reads the `id` column)
 * is witnessed by the albums read carrying the seeded id.
 */
#[Group('spec:creating-resources')]
final class IdsTest extends MusicCatalogTestCase
{
    use AssertsSpecCompliance;

    private const CLIENT_PLAYLIST_ID = 'a1a2a3a4-b1b2-4c3c-8d4d-e1e2e3e4e5e6';

    #[Test]
    public function aServerGeneratedIdIsAssignedWhenNoneIsSupplied(): void
    {
        // albums declares uuid()->generated(): no `id` in the request → the app
        // mints a UUID.
        $response = $this->post('/albums', [
            'data' => [
                'type' => 'albums',
                'attributes' => ['title' => 'Hail to the Thief'],
            ],
        ]);

        self::assertSame(201, $response->getStatusCode());

        $id = $this->single($response)['id'] ?? null;
        self::assertIsString($id);
        self::assertNotSame('', $id, 'a server-generated id is present');
    }

    #[Test]
    public function aClientGeneratedIdIsRejectedByADefaultResource(): void
    {
        // albums does NOT opt in to client ids, so supplying one is a 403
        // ClientGeneratedIdNotSupported (the spec lets the server reject it).
        $response = $this->post('/albums', [
            'data' => [
                'type' => 'albums',
                'id' => '99999999-9999-4999-8999-999999999999',
                'attributes' => ['title' => 'Amnesiac'],
            ],
        ]);

        self::assertSame(403, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);
    }

    #[Test]
    public function aPlaylistAcceptsAClientGeneratedUuidId(): void
    {
        // PlaylistResource opts in (Id::make()->uuid()->allowClientId()), so a
        // client-supplied UUID is honoured verbatim. `public:
        // true` keeps it clear of the premium (402) guard for private playlists.
        $response = $this->post('/playlists', [
            'data' => [
                'type' => 'playlists',
                'id' => self::CLIENT_PLAYLIST_ID,
                'attributes' => [
                    'title' => 'Late Night',
                    'public' => true,
                ],
            ],
        ]);

        self::assertSame(201, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);

        JsonApiDocument::of($response)
            ->assertHasType('playlists')
            ->assertHasId(self::CLIENT_PLAYLIST_ID);

        // The Location header carries the client-chosen id (not a server-minted one).
        self::assertSame(
            'https://music.example/playlists/' . self::CLIENT_PLAYLIST_ID,
            $response->getHeaderLine('Location'),
        );
    }

    #[Test]
    public function aClientGeneratedPlaylistIsReadableAtItsChosenId(): void
    {
        $this->post('/playlists', [
            'data' => [
                'type' => 'playlists',
                'id' => self::CLIENT_PLAYLIST_ID,
                'attributes' => [
                    'title' => 'Deep Focus',
                    'public' => true,
                ],
            ],
        ]);

        $fetched = $this->get('/playlists/' . self::CLIENT_PLAYLIST_ID);

        self::assertSame(200, $fetched->getStatusCode());
        JsonApiDocument::of($fetched)
            ->assertHasType('playlists')
            ->assertHasId(self::CLIENT_PLAYLIST_ID)
            ->assertHasAttribute('title', 'Deep Focus');
    }

    #[Test]
    public function theImplicitIdDefaultReadsTheIdColumn(): void
    {
        // albums declares Id::make() with no source column, so the top-level id is
        // read from the domain object's `id` property and rendered as a string.
        $response = $this->get('/albums/2');

        self::assertSame(200, $response->getStatusCode());
        JsonApiDocument::of($response)->assertHasId('2');
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

    private function get(string $path): ResponseInterface
    {
        return $this->server()->handle(new ServerRequest('GET', 'https://music.example' . $path, [
            'Accept' => 'application/vnd.api+json',
        ]));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(string $path, array $body): ResponseInterface
    {
        return $this->server()->handle(new ServerRequest(
            'POST',
            'https://music.example' . $path,
            [
                'Accept' => 'application/vnd.api+json',
                'Content-Type' => 'application/vnd.api+json',
            ],
            (string) \json_encode($body),
        ));
    }
}
