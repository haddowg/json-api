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
 * The runnable backing for `docs/pagination.md`.
 *
 * Pagination is two pieces: a strategy reading `page[...]` into a Page VO, and the
 * data layer's push-down window → slice → count → paginate loop (see
 * {@see \haddowg\JsonApi\Examples\MusicCatalog\Data\InMemoryRepository}). The
 * server registers a default {@see \haddowg\JsonApi\Pagination\PagePaginator}
 * (`page[number]`/`page[size]`); per-relation paginators apply on
 * album→tracks and playlist→tracks (two-per-page).
 *
 * Tests assert the emitted `links.{first,prev,next,last}` and `meta.page` across
 * page boundaries, the window offsets, and the query-string-preserving links.
 *
 * NOTE: the `explicit` filter on tracks defaults to false, so a plain /tracks
 * collection holds the 3 non-explicit tracks (not all 4). These assertions are
 * computed over that default-filtered total of 3.
 */
#[Group('spec:pagination')]
final class PaginationTest extends MusicCatalogTestCase
{
    use AssertsSpecCompliance;

    #[Test]
    public function aCollectionPaginatesWithPageNumberAndSize(): void
    {
        // 3 non-explicit tracks, page[size]=2 → page 1 holds 2 items, with next +
        // last links.
        $response = $this->get('/tracks?page[number]=1&page[size]=2&sort=trackNumber');

        self::assertSame(200, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);

        $doc = JsonApiDocument::of($response);
        self::assertCount(2, $this->listData($doc));

        $links = $doc->links();
        self::assertArrayHasKey('first', $links);
        self::assertArrayHasKey('last', $links);
        self::assertArrayHasKey('next', $links);
        // page 1: no prev link (or null at the boundary).
        self::assertTrue(($links['prev'] ?? null) === null);
    }

    #[Test]
    public function pageMetaCarriesTheCountSummary(): void
    {
        $response = $this->get('/tracks?page[number]=2&page[size]=2&sort=trackNumber');

        self::assertSame(200, $response->getStatusCode());

        $page = $this->pageMeta($response);
        self::assertSame(2, $page['currentPage'] ?? null);
        self::assertSame(2, $page['perPage'] ?? null);
        self::assertSame(3, $page['total'] ?? null);
        self::assertSame(2, $page['lastPage'] ?? null);
    }

    #[Test]
    public function theLastPageHasPrevButNoNext(): void
    {
        $response = $this->get('/tracks?page[number]=2&page[size]=2&sort=trackNumber');

        self::assertSame(200, $response->getStatusCode());

        $doc = JsonApiDocument::of($response);
        self::assertCount(1, $this->listData($doc), 'the second page of 3 holds the remaining 1');

        $links = $doc->links();
        self::assertArrayHasKey('prev', $links);
        self::assertTrue(($links['next'] ?? null) === null, 'no next on the last page');
    }

    #[Test]
    public function theWindowSlicesADifferentSetPerPage(): void
    {
        // Over the 3 non-explicit tracks ordered by trackNumber,title:
        // Airbag(1), Mysterons(1), Exit Music(3). Page 1 (size 2) and page 2 must
        // be disjoint.
        $page1 = $this->ids($this->get('/tracks?page[number]=1&page[size]=2&sort=trackNumber,title'));
        $page2 = $this->ids($this->get('/tracks?page[number]=2&page[size]=2&sort=trackNumber,title'));

        self::assertCount(2, $page1);
        self::assertCount(1, $page2);
        self::assertSame([], \array_intersect($page1, $page2), 'pages must not overlap');
    }

    #[Test]
    public function paginationLinksPreserveTheSortQuery(): void
    {
        // Links are absolute and query-string-preserving so sort/filter survive
        // across pages.
        $response = $this->get('/tracks?page[size]=2&sort=trackNumber');

        $links = JsonApiDocument::of($response)->links();
        self::assertArrayHasKey('next', $links);
        self::assertStringContainsString('sort=trackNumber', \urldecode($this->href($links['next'])));
    }

    #[Test]
    public function aGarbagePageParameterDoesNotError(): void
    {
        // Garbage page input clamps to a sane window rather than 400-ing.
        $response = $this->get('/tracks?page[number]=-5&page[size]=abc');

        self::assertSame(200, $response->getStatusCode());
        $this->assertJsonApiSpecCompliant($response);
    }

    #[Test]
    public function theServerDefaultPaginatorAppliesWhenNoPageIsRequested(): void
    {
        // No page[...]: the server default (perPage=10) still emits a page meta.
        $response = $this->get('/tracks');

        self::assertSame(200, $response->getStatusCode());

        $page = $this->pageMeta($response);
        self::assertSame(3, $page['total'] ?? null);
        self::assertSame(10, $page['perPage'] ?? null);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listData(JsonApiDocument $doc): array
    {
        $data = $doc->data();
        self::assertIsArray($data);

        $rows = [];
        foreach ($data as $row) {
            self::assertIsArray($row);
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function pageMeta(ResponseInterface $response): array
    {
        $meta = JsonApiDocument::of($response)->meta();
        self::assertArrayHasKey('page', $meta);
        $page = $meta['page'];
        self::assertIsArray($page);

        return $page;
    }

    /**
     * @return list<string>
     */
    private function ids(ResponseInterface $response): array
    {
        $ids = [];
        foreach ($this->listData(JsonApiDocument::of($response)) as $row) {
            $id = $row['id'] ?? null;
            self::assertIsString($id);
            $ids[] = $id;
        }

        return $ids;
    }

    private function href(mixed $link): string
    {
        if (\is_array($link)) {
            $href = $link['href'] ?? '';

            return \is_string($href) ? $href : '';
        }

        return \is_string($link) ? $link : '';
    }

    private function get(string $path): ResponseInterface
    {
        return $this->server()->handle(new ServerRequest('GET', 'https://music.example' . $path, [
            'Accept' => 'application/vnd.api+json',
        ]));
    }
}
