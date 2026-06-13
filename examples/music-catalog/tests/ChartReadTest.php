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
 * The READ-path backing for the standalone `charts` type (CORRECTION 3).
 *
 * `charts` is registered with `registerSerializerHydrator('charts', serializer:
 * ChartSerializer::class)` — a bare serializer under a TYPE-STRING key, with NO
 * Resource and NO hydrator. It is read-only: only `GET /charts` and
 * `GET /charts/{id}` route; its URI segment resolves via `UriTypeAwareInterface`.
 *
 * This suite exercises only the HTTP read surface (fetch single + collection +
 * not-found). The registration-resolver internals (the type-string vs class-string
 * key, the `hasSerializerFor`/`hasHydratorFor` mirror, the `NoResourceRegistered`
 * boundary, and the write-method 404s) are asserted in
 * {@see CapabilityCompositionTest}; if both files end up covering the fetch, the
 * overlap is harmless and can be de-duplicated.
 */
#[Group('spec:fetching-resources')]
final class ChartReadTest extends MusicCatalogTestCase
{
    use AssertsSpecCompliance;

    #[Test]
    public function fetchingASingleChartRendersTheBareSerializerDocument(): void
    {
        $response = $this->get('/charts/1');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/vnd.api+json', $response->getHeaderLine('Content-Type'));
        $this->assertJsonApiSpecCompliant($response);

        JsonApiDocument::of($response)
            ->assertHasType('charts')
            ->assertHasId('1')
            ->assertHasAttribute('name', 'Weekly Top')
            ->assertHasAttribute('period', '2024-W03');
    }

    #[Test]
    public function aChartRendersItsEntriesAttribute(): void
    {
        $response = $this->get('/charts/1');

        self::assertSame(200, $response->getStatusCode());

        $data = $this->single($response);
        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);

        $entries = $attributes['entries'] ?? null;
        self::assertIsArray($entries);
        self::assertCount(3, $entries);

        $first = $entries[0] ?? null;
        self::assertIsArray($first);
        self::assertSame(1, $first['rank'] ?? null);
        self::assertSame('2', $first['trackId'] ?? null);
    }

    #[Test]
    public function fetchingTheChartCollectionRendersAList(): void
    {
        $response = $this->get('/charts');

        self::assertSame(200, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);

        $data = JsonApiDocument::of($response)->data();
        self::assertIsArray($data);
        self::assertCount(1, $data);

        $first = $data[0] ?? null;
        self::assertIsArray($first);
        self::assertSame('charts', $first['type'] ?? null);
    }

    #[Test]
    public function aMissingChartRendersA404(): void
    {
        $response = $this->get('/charts/999');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function theChartUriSegmentResolvesViaTheTypeString(): void
    {
        // UriTypeAwareInterface returns 'charts', so the URL path segment matches
        // the type string and the fetch routes successfully.
        $response = $this->get('/charts/1');

        self::assertSame(200, $response->getStatusCode());
        JsonApiDocument::of($response)->assertHasType('charts');
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
}
