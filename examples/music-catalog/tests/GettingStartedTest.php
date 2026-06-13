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
 * The runnable backing for `docs/getting-started.md`.
 *
 * It walks the three headline outcomes the onboarding page promises, end-to-end
 * through the wired {@see \haddowg\JsonApi\Server\Server} the example app boots:
 *
 *  - `GET /albums/1` → `200` + a spec-compliant single-resource document;
 *  - `POST /albums` (a full request envelope) → `201` with a `Location` header that
 *    echoes the server-generated id, and the created resource immediately readable
 *    (read and write share one in-memory store);
 *  - `GET /albums/999` → `404` rendered from {@see \haddowg\JsonApi\Exception\ResourceNotFound}.
 *
 * Every body is asserted spec-compliant.
 */
#[Group('spec:fetching-resources')]
final class GettingStartedTest extends MusicCatalogTestCase
{
    use AssertsSpecCompliance;

    #[Test]
    public function fetchingASingleAlbumReturnsASpecCompliantDocument(): void
    {
        $response = $this->get('/albums/1');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/vnd.api+json', $response->getHeaderLine('Content-Type'));
        $this->assertJsonApiSpecCompliant($response);

        JsonApiDocument::of($response)
            ->assertHasType('albums')
            ->assertHasId('1')
            ->assertHasAttribute('title', 'OK Computer');
    }

    #[Test]
    public function creatingAnAlbumReturns201WithALocationHeader(): void
    {
        // A full create envelope: a `data` member of type `albums` with attributes.
        // No client id is supplied, so the server generates one (the default
        // RFC-4122 v4 UUID) and echoes it in the `Location` header.
        $response = $this->post('/albums', [
            'data' => [
                'type' => 'albums',
                'attributes' => [
                    'title' => 'In Rainbows',
                    'explicit' => false,
                ],
            ],
        ]);

        self::assertSame(201, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);

        $doc = JsonApiDocument::of($response);
        $doc->assertHasType('albums')->assertHasAttribute('title', 'In Rainbows');

        // The server generated the id; Location is baseUri + /albums/{id}.
        $data = $this->single($response);
        $id = $data['id'] ?? null;
        self::assertIsString($id);
        self::assertNotSame('', $id);

        self::assertSame(
            'https://music.example/albums/' . $id,
            $response->getHeaderLine('Location'),
            'Location echoes the server-generated id',
        );
    }

    #[Test]
    public function aCreatedAlbumIsImmediatelyReadable(): void
    {
        // Read and write share one in-memory store, so the resource POSTed above is
        // fetchable straight away through the same server.
        $created = $this->post('/albums', [
            'data' => [
                'type' => 'albums',
                'attributes' => ['title' => 'Kid A'],
            ],
        ]);

        self::assertSame(201, $created->getStatusCode());
        $id = $this->id($created);
        self::assertNotSame('', $id);

        $fetched = $this->get('/albums/' . $id);

        self::assertSame(200, $fetched->getStatusCode());
        JsonApiDocument::of($fetched)
            ->assertHasType('albums')
            ->assertHasId($id)
            ->assertHasAttribute('title', 'Kid A');
    }

    #[Test]
    public function fetchingAMissingAlbumReturns404(): void
    {
        $response = $this->get('/albums/999');

        self::assertSame(404, $response->getStatusCode());
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
