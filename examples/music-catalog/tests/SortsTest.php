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
 * The runnable backing for `docs/sorts.md`.
 *
 * Sorts are metadata-only: marking a field `->sortable()` auto-derives a
 * `SortByField` (merged into `allSorts()`), and `sort=a,-b` is parsed off the
 * request. Ordering itself lives in the data layer — the catalog's
 * {@see \haddowg\JsonApi\Examples\MusicCatalog\Data\CriteriaApplier} delegates
 * field sorts to the reference
 * {@see \haddowg\JsonApi\Resource\Sort\InMemory\ArraySortHandler} and executes the
 * computed {@see \haddowg\JsonApi\Examples\MusicCatalog\Sort\TrackCountSort} in a
 * pre-arm.
 *
 * Each test asserts the *ordering* of the rendered primary data.
 *
 * NOTE: the `explicit` filter on tracks declares `default(false)`, so every plain
 * `/tracks` collection request implicitly excludes the one explicit track
 * ("Paranoid Android") via {@see \haddowg\JsonApi\Resource\Filter\FilterDefaults}.
 * The three non-explicit tracks (Airbag, Exit Music, Mysterons) are what these
 * sorts order; that default-exclusion interaction is asserted in {@see FiltersTest}.
 */
#[Group('spec:sorting')]
final class SortsTest extends MusicCatalogTestCase
{
    use AssertsSpecCompliance;

    #[Test]
    public function sortableFieldIsAutoDerivedAndAscendingByDefault(): void
    {
        // tracks: title is ->sortable(). Ascending by title over the three
        // non-explicit tracks: Airbag, Exit Music (For a Film), Mysterons.
        $response = $this->get('/tracks?sort=title');

        self::assertSame(200, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);

        self::assertSame(
            ['Airbag', 'Exit Music (For a Film)', 'Mysterons'],
            $this->values($response, 'title'),
        );
    }

    #[Test]
    public function aLeadingDashSortsDescending(): void
    {
        $response = $this->get('/tracks?sort=-title');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['Mysterons', 'Exit Music (For a Film)', 'Airbag'],
            $this->values($response, 'title'),
        );
    }

    #[Test]
    public function multipleSortKeysApplyMostSignificantFirst(): void
    {
        // sort=trackNumber,-title over the three non-explicit tracks: primary
        // trackNumber ascending, ties broken by title descending. trackNumber 1:
        // Mysterons (album 2) + Airbag (album 1); descending title puts Mysterons
        // before Airbag. Then trackNumber 3: Exit Music.
        $response = $this->get('/tracks?sort=trackNumber,-title');

        self::assertSame(200, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);

        self::assertSame(
            ['Mysterons', 'Airbag', 'Exit Music (For a Film)'],
            $this->values($response, 'title'),
        );
    }

    #[Test]
    public function aComputedCustomSortIsExecutedByTheCriteriaApplierPreArm(): void
    {
        // artists declares the computed TrackCountSort. trackCount: Radiohead 3,
        // Portishead 1. Descending puts Radiohead first.
        $response = $this->get('/artists?sort=-trackCount');

        self::assertSame(200, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);

        self::assertSame(['Radiohead', 'Portishead'], $this->values($response, 'name'));
    }

    #[Test]
    public function aComputedCustomSortAscendingReversesTheOrder(): void
    {
        $response = $this->get('/artists?sort=trackCount');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['Portishead', 'Radiohead'], $this->values($response, 'name'));
    }

    #[Test]
    public function anUnknownSortKeyIsIgnoredRatherThanReordering(): void
    {
        // A sort key matching no declared/derived sort is skipped by the applier;
        // insertion order (Radiohead, Portishead) is preserved.
        $response = $this->get('/artists?sort=unknownColumn');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['Radiohead', 'Portishead'], $this->values($response, 'name'));
    }

    /**
     * The named attribute of each primary resource, in render order.
     *
     * @return list<mixed>
     */
    private function values(ResponseInterface $response, string $attribute): array
    {
        $data = JsonApiDocument::of($response)->data();
        self::assertIsArray($data);

        $values = [];
        foreach ($data as $row) {
            self::assertIsArray($row);
            $attributes = $row['attributes'] ?? null;
            self::assertIsArray($attributes);
            $values[] = $attributes[$attribute] ?? null;
        }

        return $values;
    }

    private function get(string $path): ResponseInterface
    {
        return $this->server()->handle(new ServerRequest('GET', 'https://music.example' . $path, [
            'Accept' => 'application/vnd.api+json',
        ]));
    }
}
