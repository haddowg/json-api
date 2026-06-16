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
 * The runnable backing for `docs/sparse-fieldsets-and-includes.md`.
 *
 * Two query parameters shape the payload: `fields[TYPE]` narrows which members
 * render (Id always present), and `include` builds a compound document with a
 * deduplicated `included` array. This suite also pins **CORRECTION 1**: default
 * includes are realised purely by overriding
 * {@see \haddowg\JsonApi\Resource\AbstractResource::getDefaultIncludedRelationships()}
 * (AlbumResource returns `['artist']`) — there is no fluent "include by default"
 * field method. A `GET /albums/1` with no `?include` therefore emits the artist;
 * an explicit `?include` suppresses the default.
 */
#[Group('spec:sparse-fieldsets')]
final class SparseFieldsetsAndIncludesTest extends MusicCatalogTestCase
{
    use AssertsSpecCompliance;

    #[Test]
    public function sparseFieldsetNarrowsTheRenderedAttributes(): void
    {
        $response = $this->get('/albums/1?fields[albums]=title');

        self::assertSame(200, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);

        self::assertSame(['title'], \array_keys($this->attributes($this->single($response))));
    }

    #[Test]
    public function sparseFieldsetAlwaysKeepsTheId(): void
    {
        // Id is exempt from sparse-fieldset narrowing — it is structural, not an
        // attribute member.
        $data = $this->single($this->get('/albums/1?fields[albums]=title'));

        self::assertSame('1', $data['id'] ?? null);
        self::assertSame('albums', $data['type'] ?? null);
    }

    #[Test]
    public function sparseFieldsetCanSelectARelationshipMember(): void
    {
        // fields[albums]=title,artist keeps the title attribute and the artist
        // relationship, dropping the other members.
        $response = $this->get('/albums/1?fields[albums]=title,artist');

        self::assertSame(200, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);

        $data = $this->single($response);
        self::assertSame(['title'], \array_keys($this->attributes($data)));

        $relationships = $data['relationships'] ?? null;
        self::assertIsArray($relationships);
        self::assertArrayHasKey('artist', $relationships);
        self::assertArrayNotHasKey('tracks', $relationships);
    }

    #[Test]
    public function defaultIncludedRelationshipAppliesWithNoIncludeParam(): void
    {
        // CORRECTION 1: AlbumResource::getDefaultIncludedRelationships() returns
        // ['artist'], so a bare GET /albums/1 yields the artist in `included`.
        $response = $this->get('/albums/1');

        self::assertSame(200, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);

        JsonApiDocument::of($response)
            ->assertHasType('albums')
            ->assertHasId('1')
            ->assertHasIncluded('artists', 1);
    }

    #[Test]
    public function anExplicitIncludeSuppressesTheDefaultInclude(): void
    {
        // ?include=tracks is present, so the default ['artist'] is NOT applied:
        // tracks are included, the artist is not.
        $response = $this->get('/albums/1?include=tracks');

        self::assertSame(200, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);

        JsonApiDocument::of($response)
            ->assertHasIncluded('tracks')
            ->assertNotHasIncluded('artists');
    }

    #[Test]
    public function includeBuildsACompoundDocument(): void
    {
        $response = $this->get('/albums/1?include=artist,tracks');

        self::assertSame(200, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);

        JsonApiDocument::of($response)
            ->assertHasType('albums')
            ->assertHasIncluded('artists', 1)
            ->assertHasIncluded('tracks', 3);
    }

    #[Test]
    public function includedResourcesAreDeduplicated(): void
    {
        // GET /albums (collection) with ?include=artist. A compound document must
        // not carry duplicate {type,id} pairs in `included`.
        $response = $this->get('/albums?include=artist');

        self::assertSame(200, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);

        $included = JsonApiDocument::of($response)->included();
        $keys = [];
        foreach ($included as $resource) {
            self::assertIsArray($resource);
            $type = $resource['type'] ?? '';
            $id = $resource['id'] ?? '';
            self::assertIsString($type);
            self::assertIsString($id);
            $keys[] = $type . ':' . $id;
        }
        self::assertSame($keys, \array_values(\array_unique($keys)), 'included must be deduplicated');
    }

    #[Test]
    public function fieldsetAppliesToIncludedResources(): void
    {
        // fields[artists]=name narrows the included artist's attributes too.
        $response = $this->get('/albums/1?include=artist&fields[artists]=name');

        self::assertSame(200, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);

        $artist = $this->firstOfType(JsonApiDocument::of($response)->included(), 'artists');
        self::assertNotNull($artist);
        self::assertSame(['name'], \array_keys($this->attributes($artist)));
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
     * @param array<string, mixed> $resource
     *
     * @return array<string, mixed>
     */
    private function attributes(array $resource): array
    {
        $attributes = $resource['attributes'] ?? null;
        self::assertIsArray($attributes);

        return $attributes;
    }

    /**
     * @param list<mixed> $included
     *
     * @return array<string, mixed>|null
     */
    private function firstOfType(array $included, string $type): ?array
    {
        foreach ($included as $resource) {
            if (\is_array($resource) && ($resource['type'] ?? null) === $type) {
                return $resource;
            }
        }

        return null;
    }

    private function get(string $path): ResponseInterface
    {
        return $this->server()->handle(new ServerRequest('GET', 'https://music.example' . $path, [
            'Accept' => 'application/vnd.api+json',
        ]));
    }
}
