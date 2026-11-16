<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Schema\Relationship;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Collection\CursorCollectionResult;
use haddowg\JsonApi\Pagination\CursorBasedPage;
use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Request\RelatedQuery;
use haddowg\JsonApi\Schema\Relationship\RelationshipPagination;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RelationshipPagination::class)]
final class RelationshipPaginationTest extends TestCase
{
    #[Test]
    public function aCountedFirstPageCarriesItsTotalAndALastLink(): void
    {
        $paginator = PagePaginator::make()->withDefaultPerPage(2);
        $request = RelationshipPagination::firstPageRequest(new StubJsonApiRequest());
        $items = [(object) ['id' => '1'], (object) ['id' => '2']];

        $pagination = RelationshipPagination::firstPage($paginator, $request, $items, new CollectionResult($items, 5, true), counted: true);

        self::assertSame(5, $pagination->total);
        $links = $pagination->linksFor('/albums/1/relationships/tracks');
        self::assertNotNull($links['last'] ?? null);
        self::assertNotNull($links['next'] ?? null);
    }

    #[Test]
    public function aTotalTheReadWasNotAskedForIsIgnored(): void
    {
        $paginator = PagePaginator::make()->withDefaultPerPage(2);
        $request = RelationshipPagination::firstPageRequest(new StubJsonApiRequest());
        $items = [(object) ['id' => '1'], (object) ['id' => '2']];

        $pagination = RelationshipPagination::firstPage($paginator, $request, $items, new CollectionResult($items, 5, true, true), counted: false);

        self::assertNull($pagination->total);
        $links = $pagination->linksFor('/albums/1/relationships/tracks');
        self::assertNull($links['last'] ?? null);
        self::assertNotNull($links['next'] ?? null);
    }

    #[Test]
    public function aCountFreeLastPageHasNoNextLink(): void
    {
        $paginator = PagePaginator::make()->withDefaultPerPage(2);
        $request = RelationshipPagination::firstPageRequest(new StubJsonApiRequest());
        $items = [(object) ['id' => '1']];

        $pagination = RelationshipPagination::firstPage($paginator, $request, $items, new CollectionResult($items, null, true, false), counted: false);

        self::assertNull($pagination->linksFor('/albums/1/relationships/tracks')['next'] ?? null);
    }

    #[Test]
    public function aCursorResultRendersTheCursorPaginatorsFirstPage(): void
    {
        $paginator = CursorPaginator::make();
        $request = RelationshipPagination::firstPageRequest(new StubJsonApiRequest());
        $items = [(object) ['id' => '1']];

        $pagination = RelationshipPagination::firstPage(
            $paginator,
            $request,
            $items,
            new CursorCollectionResult($items, 'b', 'a', false, true),
            counted: false,
            from: '1',
            to: '1',
        );

        self::assertInstanceOf(CursorBasedPage::class, $pagination->page);
        self::assertNull($pagination->total);
        self::assertNotNull($pagination->linksFor('/inbox/relationships/items')['next'] ?? null);
    }

    #[Test]
    public function theFirstPageRequestReplacesThePrimaryRequestsPageSortAndFilter(): void
    {
        $primary = new StubJsonApiRequest(['page' => ['number' => '3', 'size' => '50'], 'sort' => 'title', 'filter' => ['genre' => 'rock']]);

        $plain = RelationshipPagination::firstPageRequest($primary);
        self::assertSame(['number' => '1'], $plain->getQueryParam('page'));
        self::assertSame([], $plain->getSorting());
        self::assertSame([], $plain->getQueryParam('filter'));

        $queried = RelationshipPagination::firstPageRequest($primary, new RelatedQuery('-duration', ['longerThan' => '300']));
        self::assertSame(['-duration'], $queried->getSorting());
        self::assertSame(['longerThan' => '300'], $queried->getQueryParam('filter'));
    }
}
