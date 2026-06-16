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
 * The runnable backing for `docs/hydrators.md`.
 *
 * The `playlists` type is registered with a custom write override —
 * `Server::register(PlaylistResource::class, hydrator: PlaylistHydrator::class)` —
 * so the hand-written {@see \haddowg\JsonApi\Examples\MusicCatalog\Hydrator\PlaylistHydrator}
 * wins for **writes** while {@see \haddowg\JsonApi\Examples\MusicCatalog\Resource\PlaylistResource}
 * still serializes **reads** (read and write capabilities resolved independently).
 *
 * Witnesses, all end-to-end through the wired server:
 *
 *  - the headline reason to hand-write a hydrator: one client member (`title`)
 *    fans out to **two** stored columns, deriving the read-only `slug` the field
 *    DSL never lets the client set;
 *  - cardinality declared by the relationship callable's second-parameter type hint
 *    (`ToOneRelationship` `owner` / `ToManyRelationship` `tracks`) during a
 *    whole-resource write;
 *  - the {@see \haddowg\JsonApi\Hydrator\AbstractHydrator::validateDomainObject()}
 *    post-hydration seam (a derived slug must be non-empty);
 *  - the override split: the hydrator writes, the resource reads.
 *
 * `public: true` keeps these creates clear of the premium (`402`) guard.
 */
#[Group('spec:creating-resources')]
final class HydratorsTest extends MusicCatalogTestCase
{
    use AssertsSpecCompliance;

    #[Test]
    public function theCustomHydratorDerivesTheSlugFromTheTitleOnWrite(): void
    {
        // One `title` member fills the title AND derives the read-only `slug` — a
        // value the client can never set directly (Slug::make('slug')->readOnly()).
        $response = $this->post('/playlists', [
            'data' => [
                'type' => 'playlists',
                'attributes' => [
                    'title' => 'Chill Out Sessions',
                    'public' => true,
                ],
            ],
        ]);

        self::assertSame(201, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);

        // The resource serializes the read: the derived slug is visible.
        JsonApiDocument::of($response)
            ->assertHasType('playlists')
            ->assertHasAttribute('title', 'Chill Out Sessions')
            ->assertHasAttribute('slug', 'chill-out-sessions');
    }

    #[Test]
    public function aClientSuppliedSlugIsIgnoredBecauseItIsReadOnlyAndDerived(): void
    {
        // Even if the client posts a slug, the hydrator overwrites it from the title
        // (readOnly drops the client value; the hydrator owns the derivation).
        $response = $this->post('/playlists', [
            'data' => [
                'type' => 'playlists',
                'attributes' => [
                    'title' => 'Road Trip',
                    'slug' => 'totally-different',
                    'public' => true,
                ],
            ],
        ]);

        self::assertSame(201, $response->getStatusCode());
        JsonApiDocument::of($response)->assertHasAttribute('slug', 'road-trip');
    }

    #[Test]
    public function aWholeResourceWriteAppliesTheTypedRelationshipHydrators(): void
    {
        // owner is hinted ToOneRelationship, tracks is hinted ToManyRelationship —
        // the same getRelationshipHydrator() map drives a whole-resource write.
        $response = $this->post('/playlists', [
            'data' => [
                'type' => 'playlists',
                'attributes' => [
                    'title' => 'Collaborative',
                    'public' => true,
                ],
                'relationships' => [
                    'owner' => [
                        'data' => ['type' => 'users', 'id' => '1'],
                    ],
                    'tracks' => [
                        'data' => [
                            ['type' => 'tracks', 'id' => '1'],
                            ['type' => 'tracks', 'id' => '2'],
                        ],
                    ],
                ],
            ],
        ]);

        self::assertSame(201, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);

        $id = $this->id($response);
        self::assertNotSame('', $id);

        // Re-read the owner linkage and the track linkage: both relationship
        // hydrators ran.
        JsonApiDocument::of($this->get('/playlists/' . $id . '/relationships/owner'))
            ->assertHasType('users')
            ->assertHasId('1');

        $tracks = JsonApiDocument::of($this->get('/playlists/' . $id . '/relationships/tracks'))->data();
        self::assertIsArray($tracks);
        self::assertCount(2, $tracks);
    }

    #[Test]
    public function theOverrideSplitsWriteFromRead(): void
    {
        // Write via the hydrator, then read via the resource: the round-trip proves
        // the two capabilities are resolved from different objects for the same type.
        $created = $this->post('/playlists', [
            'data' => [
                'type' => 'playlists',
                'attributes' => [
                    'title' => 'Split Demo',
                    'public' => true,
                ],
            ],
        ]);

        $id = $this->id($created);
        $read = $this->get('/playlists/' . $id);

        self::assertSame(200, $read->getStatusCode());
        JsonApiDocument::of($read)
            ->assertHasType('playlists')
            ->assertHasAttribute('title', 'Split Demo')
            ->assertHasAttribute('slug', 'split-demo');
    }

    #[Test]
    public function theValidateDomainObjectSeamGuardsTheFullyHydratedObject(): void
    {
        // The post-hydration seam rejects a title that slugifies to nothing (a
        // non-empty title must yield a non-empty derived slug). It throws a plain
        // LogicException, which the (debug-off) error handler renders as a 500 — the
        // seam is the place app-level invariants the field DSL cannot express live.
        $response = $this->post('/playlists', [
            'data' => [
                'type' => 'playlists',
                'attributes' => [
                    'title' => '!!!',
                    'public' => true,
                ],
            ],
        ]);

        self::assertSame(500, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);
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

    private function id(ResponseInterface $response): string
    {
        $id = $this->single($response)['id'] ?? null;
        self::assertIsString($id);

        return $id;
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
