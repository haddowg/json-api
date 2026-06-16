<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Examples\MusicCatalog\Tests;

use haddowg\JsonApi\Examples\MusicCatalog\Data\InMemoryStore;
use haddowg\JsonApi\Examples\MusicCatalog\Domain\User;
use haddowg\JsonApi\Examples\MusicCatalog\Seed;
use haddowg\JsonApi\Testing\AssertsSpecCompliance;
use haddowg\JsonApi\Testing\JsonApiDocument;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

use function haddowg\JsonApi\Examples\MusicCatalog\bootstrapWithRepository;

/**
 * The runnable backing for the write-status-code and write-safety claims in
 * `docs/operations.md` / `docs/responses.md` / `docs/resources.md`.
 *
 * It exercises the three resource-write verbs and their status codes end-to-end —
 * `PATCH /{type}/{id}` → `200`, `DELETE /{type}/{id}` → `204` (empty body) — and the
 * two write-safety behaviours the field walk gives you for free:
 *
 *  - **over-posting drop**: an attribute the resource never declared is silently
 *    ignored (the hydrator only iterates declared `attributeFields()`), so a client
 *    cannot smuggle arbitrary state in;
 *  - **read-only drop**: a `readOnly()` field present in the body is skipped during
 *    hydration (e.g. the server-computed `averageRating`);
 *  - **write-only**: a `writeOnly()` field (the users `password`) is hydrated and
 *    validated on write but never rendered — absent from every response, not null —
 *    so its hydration is witnessed against the store.
 *
 * The `PATCH` absence-is-no-change semantics (a member omitted from a `PATCH` body
 * leaves the stored value untouched) is witnessed too. The create-side status
 * (`201` + `Location`) lives in {@see GettingStartedTest}.
 */
#[Group('spec:updating-resources')]
final class WritesTest extends MusicCatalogTestCase
{
    use AssertsSpecCompliance;

    #[Test]
    public function patchingAnAlbumReturns200WithTheUpdatedResource(): void
    {
        $response = $this->patch('/albums/1', [
            'data' => [
                'type' => 'albums',
                'id' => '1',
                'attributes' => ['title' => 'OK Computer (Remastered)'],
            ],
        ]);

        self::assertSame(200, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);

        JsonApiDocument::of($response)
            ->assertHasType('albums')
            ->assertHasId('1')
            ->assertHasAttribute('title', 'OK Computer (Remastered)');
    }

    #[Test]
    public function aPatchedMemberPersistsAndOmittedMembersAreUnchanged(): void
    {
        // PATCH only `title`; `explicit` is omitted, so it keeps its seeded value
        // (absence = no change), and the title change persists.
        $this->patch('/albums/1', [
            'data' => [
                'type' => 'albums',
                'id' => '1',
                'attributes' => ['title' => 'Renamed'],
            ],
        ]);

        $read = JsonApiDocument::of($this->get('/albums/1'));
        $read->assertHasAttribute('title', 'Renamed');
        $read->assertHasAttribute('explicit', false); // untouched seeded value
    }

    #[Test]
    public function deletingAnAlbumReturns204WithNoBody(): void
    {
        $response = $this->delete('/albums/2');

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody(), '204 carries no body');
    }

    #[Test]
    public function aDeletedAlbumIsNoLongerReadable(): void
    {
        $this->delete('/albums/2');

        self::assertSame(404, $this->get('/albums/2')->getStatusCode());
    }

    #[Test]
    public function deletingAMissingAlbumReturns404(): void
    {
        $response = $this->delete('/albums/999');

        self::assertSame(404, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);
    }

    #[Test]
    public function patchingAMissingAlbumReturns404(): void
    {
        $response = $this->patch('/albums/999', [
            'data' => [
                'type' => 'albums',
                'id' => '999',
                'attributes' => ['title' => 'Ghost'],
            ],
        ]);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function anUndeclaredAttributeIsSilentlyDroppedOnCreate(): void
    {
        // Over-posting: `secretFlag` is not a declared field, so the hydrator never
        // reads it. The created resource carries only declared attributes.
        $response = $this->post('/albums', [
            'data' => [
                'type' => 'albums',
                'attributes' => [
                    'title' => 'A Moon Shaped Pool',
                    'secretFlag' => true,
                ],
            ],
        ]);

        self::assertSame(201, $response->getStatusCode());

        $attributes = $this->attributes($response);
        self::assertArrayHasKey('title', $attributes);
        self::assertArrayNotHasKey('secretFlag', $attributes, 'an undeclared attribute is never persisted');
    }

    #[Test]
    public function aReadOnlyAttributeIsDroppedOnWrite(): void
    {
        // `averageRating` is readOnly(): a client value in the body is skipped during
        // hydration, so a freshly created album has the domain default (null), never
        // the client-supplied number.
        $response = $this->post('/albums', [
            'data' => [
                'type' => 'albums',
                'attributes' => [
                    'title' => 'The Bends',
                    'averageRating' => 10.0,
                ],
            ],
        ]);

        self::assertSame(201, $response->getStatusCode());

        $attributes = $this->attributes($response);
        self::assertArrayHasKey('averageRating', $attributes);
        self::assertNull($attributes['averageRating'], 'a readOnly field never accepts a client value');
    }

    #[Test]
    public function aWriteOnlyAttributeIsHydratedButNeverRendered(): void
    {
        // users declares Str::make('password')->writeOnly(): accepted and validated
        // on write, but never serialized — the member is dropped from the rendered
        // attributes (absent, not null). Ada is seeded at id 1, so the store mints
        // id 2 for this create.
        $response = $this->post('/users', [
            'data' => [
                'type' => 'users',
                'attributes' => [
                    'email' => 'grace@example.com',
                    'displayName' => 'Grace',
                    'password' => 's3cret-pass',
                ],
            ],
        ]);

        self::assertSame(201, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);

        // Absent — not null — from the created resource's attributes.
        $created = $this->attributes($response);
        self::assertArrayNotHasKey('password', $created, 'a write-only field is never rendered');
        self::assertArrayHasKey('email', $created, 'other attributes still render');

        // ...and still absent on a subsequent GET of the same resource.
        $read = $this->attributes($this->get('/users/2'));
        self::assertArrayNotHasKey('password', $read, 'a write-only field is absent on read');
        self::assertSame('Grace', $read['displayName'] ?? null);
    }

    #[Test]
    public function aWriteOnlyAttributeStillHydratesIntoTheStoredEntity(): void
    {
        // The write-only `password` is dropped from every response, so its hydration
        // is invisible over the wire — assert it directly against the shared store:
        // the created User carries the posted password (the write took).
        $store = new InMemoryStore();
        Seed::into($store);
        [$server] = bootstrapWithRepository($store);

        $response = $server->handle(new ServerRequest(
            'POST',
            'https://music.example/users',
            [
                'Accept' => 'application/vnd.api+json',
                'Content-Type' => 'application/vnd.api+json',
            ],
            (string) \json_encode([
                'data' => [
                    'type' => 'users',
                    'attributes' => [
                        'email' => 'grace@example.com',
                        'displayName' => 'Grace',
                        'password' => 's3cret-pass',
                    ],
                ],
            ]),
        ));

        self::assertSame(201, $response->getStatusCode());

        $stored = $store->find('users', '2');
        self::assertInstanceOf(User::class, $stored);
        self::assertSame('s3cret-pass', $stored->password, 'the write-only value hydrated onto the entity');
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(ResponseInterface $response): array
    {
        $data = JsonApiDocument::of($response)->data();
        self::assertIsArray($data);
        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);

        /** @var array<string, mixed> $attributes */
        return $attributes;
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
        return $this->write('POST', $path, $body);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function patch(string $path, array $body): ResponseInterface
    {
        return $this->write('PATCH', $path, $body);
    }

    private function delete(string $path): ResponseInterface
    {
        return $this->server()->handle(new ServerRequest(
            'DELETE',
            'https://music.example' . $path,
            ['Accept' => 'application/vnd.api+json'],
        ));
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
