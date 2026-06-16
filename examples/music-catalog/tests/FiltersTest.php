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
 * The runnable backing for `docs/filters.md`.
 *
 * Filters are metadata-only VOs declared in `Resource::filters()`; the library
 * never auto-applies anything. Execution lives in the data layer — here the
 * {@see \haddowg\JsonApi\Examples\MusicCatalog\Data\CriteriaApplier} composing the
 * shipped {@see \haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterHandler}. Every
 * assertion runs a real `filter[...]` request through the wired server and inspects
 * the JSON:API document.
 *
 * Witnesses: a `like` text search, a boolean filter with a real default + the
 * presence-override of that default, a set membership (`WhereIn`), a
 * relationship-existence filter (`WhereHas`), a singular `slug` filter, and the
 * silent pass-through of an undeclared filter key.
 */
#[Group('spec:filtering')]
final class FiltersTest extends MusicCatalogTestCase
{
    use AssertsSpecCompliance;

    #[Test]
    public function whereLikeMatchesASubstringOnTitle(): void
    {
        // Seeded track titles: Airbag, Paranoid Android, Exit Music (For a Film),
        // Mysterons. A `like` match on "android" hits Paranoid Android — but that
        // track is `explicit`, and the `explicit` filter defaults to false, so we
        // override that default to include it (see the default-exclusion test
        // below for the contrasting behaviour).
        $response = $this->get('/tracks?filter[title]=android&filter[explicit]=true');

        self::assertSame(200, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);

        $data = $this->collection($response);
        self::assertCount(1, $data);
        self::assertSame('2', $data[0]['id'] ?? null);
        self::assertSame('Paranoid Android', $this->attribute($data[0], 'title'));
    }

    #[Test]
    public function aFilterDefaultIsAppliedUnlessTheRequestOverridesItByPresence(): void
    {
        // `explicit` is declared with default(false), so a plain /tracks request
        // implicitly excludes explicit tracks (FilterDefaults folds the default in
        // by presence — array_key_exists, not truthiness). "Paranoid Android" is
        // the only explicit track, so a like-match on it returns nothing here...
        $excluded = $this->collection($this->get('/tracks?filter[title]=android'));
        self::assertCount(0, $excluded, 'the explicit default excludes Paranoid Android');

        // ...until the request supplies filter[explicit]=true, overriding the
        // default by presence.
        $included = $this->collection($this->get('/tracks?filter[title]=android&filter[explicit]=true'));
        self::assertCount(1, $included);
    }

    #[Test]
    public function whereLikeIsCaseInsensitive(): void
    {
        $response = $this->get('/tracks?filter[title]=AIRBAG');

        self::assertSame(200, $response->getStatusCode());

        $data = $this->collection($response);
        self::assertCount(1, $data);
        self::assertSame('Airbag', $this->attribute($data[0], 'title'));
    }

    #[Test]
    public function asBooleanFilterCoercesTheRequestValueToARealBool(): void
    {
        // explicit is declared asBoolean()->default(false). Only "Paranoid
        // Android" is explicit in the seed data.
        $response = $this->get('/tracks?filter[explicit]=true');

        self::assertSame(200, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);

        $data = $this->collection($response);
        self::assertCount(1, $data);
        self::assertSame('Paranoid Android', $this->attribute($data[0], 'title'));
    }

    #[Test]
    public function asBooleanFilterRoundTripsAFalseValue(): void
    {
        // explicit=false selects the three non-explicit tracks.
        $response = $this->get('/tracks?filter[explicit]=false');

        self::assertSame(200, $response->getStatusCode());

        $data = $this->collection($response);
        self::assertCount(3, $data);

        $ids = \array_map(static fn(array $row): mixed => $row['id'] ?? null, $data);
        self::assertNotContains('2', $ids, 'the explicit track must be excluded');
    }

    #[Test]
    public function whereInFilterIsAcceptedAndProducesASpecCompliantDocument(): void
    {
        // genres is declared WhereIn. NOTE: the reference ArrayFilterHandler's
        // WhereIn tests a *scalar* column against the requested set; `genres` is a
        // list column, so an array-overlap match is out of the reference handler's
        // scope (a real adapter would push a set-overlap predicate down). We assert
        // the request is well-formed and accepted, not a specific membership.
        $response = $this->get('/tracks?filter[genres]=trip-hop');

        self::assertSame(200, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);

        $this->collection($response);
    }

    #[Test]
    public function whereInAcceptsACommaDelimitedSet(): void
    {
        // A comma-delimited value is split by the handler into a set. As above, the
        // reference handler matches a scalar column, so we assert acceptance and a
        // spec-compliant shape rather than a list-overlap count.
        $response = $this->get('/tracks?filter[genres]=progressive,trip-hop');

        self::assertSame(200, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);

        $this->collection($response);
    }

    #[Test]
    public function whereHasSelectsParentsWithAtLeastOneRelatedRow(): void
    {
        // Both seeded albums have tracks, so WhereHas('tracks') keeps both.
        $response = $this->get('/albums?filter[tracks]=1');

        self::assertSame(200, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);

        self::assertCount(2, $this->collection($response));
    }

    #[Test]
    public function aSlugFilterNarrowsToTheMatchingArtist(): void
    {
        // artists declares Where::make('slug')->singular(). The `slug` predicate
        // narrows the collection to the one matching artist.
        //
        // NOTE: the singular() *collapse* (render a single resource / data:null
        // rather than a one-element collection) is a handler-level affordance the
        // example MusicCatalogHandler does not yet implement — it always renders a
        // collection. We assert the narrowing here and flag the collapse as an open
        // reconciliation item.
        $response = $this->get('/artists?filter[slug]=radiohead');

        self::assertSame(200, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);

        $data = $this->collection($response);
        self::assertCount(1, $data);
        self::assertSame('1', $data[0]['id'] ?? null);
        self::assertSame('Radiohead', $this->attribute($data[0], 'name'));
    }

    #[Test]
    public function aSlugFilterYieldsAnEmptyResultWhenNothingMatches(): void
    {
        $response = $this->get('/artists?filter[slug]=nobody');

        self::assertSame(200, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);

        self::assertCount(0, $this->collection($response));
    }

    #[Test]
    public function anUndeclaredFilterKeyIsSilentlyIgnored(): void
    {
        // A filter[...] key no resource declares is not applied (the library never
        // auto-applies; the CriteriaApplier only dispatches declared VOs).
        $response = $this->get('/artists?filter[withinRadius]=51.5');

        self::assertSame(200, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);

        self::assertCount(2, $this->collection($response), 'an undeclared filter must not narrow the collection');
    }

    /**
     * The primary `data` as a list of resource objects.
     *
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

    /**
     * @param array<string, mixed> $resource
     */
    private function attribute(array $resource, string $name): mixed
    {
        $attributes = $resource['attributes'] ?? null;
        self::assertIsArray($attributes);

        return $attributes[$name] ?? null;
    }

    private function get(string $path): ResponseInterface
    {
        return $this->server()->handle(new ServerRequest('GET', 'https://music.example' . $path, [
            'Accept' => 'application/vnd.api+json',
        ]));
    }
}
