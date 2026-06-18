<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Pagination;

use haddowg\JsonApi\Pagination\CursorBasedPage;
use haddowg\JsonApi\Pagination\CursorPaginationProfile;
use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Pagination\FixedPagePage;
use haddowg\JsonApi\Pagination\FixedPagePaginator;
use haddowg\JsonApi\Pagination\OffsetBasedPage;
use haddowg\JsonApi\Pagination\OffsetPaginator;
use haddowg\JsonApi\Pagination\OffsetWindow;
use haddowg\JsonApi\Pagination\PageBasedPage;
use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:pagination')]
final class PaginatorTest extends TestCase
{
    #[Test]
    public function pagePaginatorReadsNumberAndSize(): void
    {
        $request = StubJsonApiRequest::create(['page' => ['number' => '3', 'size' => '20']]);

        $page = PagePaginator::make()->paginate($request, ['a'], 100);

        self::assertInstanceOf(PageBasedPage::class, $page);
        self::assertSame(3, $page->page);
        self::assertSame(20, $page->size);
        self::assertSame(100, $page->totalItems);
    }

    #[Test]
    public function pagePaginatorFallsBackToConfiguredDefaults(): void
    {
        $request = StubJsonApiRequest::create([]);

        $page = PagePaginator::make()->withDefaultPage(1)->withDefaultPerPage(25)->paginate($request, [], 0);

        self::assertSame(1, $page->page);
        self::assertSame(25, $page->size);
    }

    #[Test]
    public function pagePaginatorHonoursCustomKeys(): void
    {
        $request = StubJsonApiRequest::create(['page' => ['p' => '2', 'per' => '5']]);

        $page = PagePaginator::make()->withPageKey('p')->withPerPageKey('per')->paginate($request, [], 0);

        self::assertSame(2, $page->page);
        self::assertSame(5, $page->size);
    }

    #[Test]
    public function offsetPaginatorReadsOffsetAndLimit(): void
    {
        $request = StubJsonApiRequest::create(['page' => ['offset' => '40', 'limit' => '20']]);

        $page = OffsetPaginator::make()->paginate($request, [], 100);

        self::assertInstanceOf(OffsetBasedPage::class, $page);
        self::assertSame(40, $page->offset);
        self::assertSame(20, $page->limit);
    }

    #[Test]
    public function fixedPagePaginatorReadsNumberAndUsesConfiguredSize(): void
    {
        $request = StubJsonApiRequest::create(['page' => ['number' => '4']]);

        $page = FixedPagePaginator::make(25)->paginate($request, [], 100);

        self::assertInstanceOf(FixedPagePage::class, $page);
        self::assertSame(4, $page->page);
        self::assertSame(25, $page->size);
    }

    #[Test]
    public function pagePaginatorPaginateWithoutCountBuildsACountFreePage(): void
    {
        $request = StubJsonApiRequest::create(['page' => ['number' => '2', 'size' => '20']]);

        $page = PagePaginator::make()->paginateWithoutCount($request, ['a'], hasMore: true);

        self::assertInstanceOf(PageBasedPage::class, $page);
        self::assertNull($page->totalItems);
        self::assertTrue($page->hasMore);
        self::assertSame(2, $page->page);
        self::assertSame(20, $page->size);
        $meta = $page->pageMeta();
        self::assertArrayNotHasKey('total', $meta);
        self::assertArrayNotHasKey('lastPage', $meta);
        // §3a: the upper bound is derived from the rendered item count even without a
        // total — page 2 of size 20 starts at from=21; one item ⇒ to=21.
        self::assertSame(21, $meta['from'] ?? null);
        self::assertSame(21, $meta['to'] ?? null);
    }

    #[Test]
    public function aCountFreePageOmitsToForAnEmptyWindow(): void
    {
        // §3a: an empty page has no upper bound, so `to` is omitted (not from-1).
        $request = StubJsonApiRequest::create(['page' => ['number' => '3', 'size' => '10']]);

        $meta = PagePaginator::make()->paginateWithoutCount($request, [], hasMore: false)->pageMeta();

        self::assertSame(21, $meta['from'] ?? null);
        self::assertArrayNotHasKey('to', $meta);
    }

    #[Test]
    public function offsetPaginatorPaginateWithoutCountBuildsACountFreePage(): void
    {
        $request = StubJsonApiRequest::create(['page' => ['offset' => '40', 'limit' => '20']]);

        $page = OffsetPaginator::make()->paginateWithoutCount($request, ['a', 'b'], hasMore: false);

        self::assertInstanceOf(OffsetBasedPage::class, $page);
        self::assertNull($page->totalItems);
        self::assertFalse($page->hasMore);
        self::assertSame(40, $page->offset);
        $meta = $page->pageMeta();
        self::assertArrayNotHasKey('total', $meta);
        // §3a: offset 40 ⇒ from=41; two items ⇒ to=42.
        self::assertSame(41, $meta['from'] ?? null);
        self::assertSame(42, $meta['to'] ?? null);
    }

    #[Test]
    public function fixedPagePaginatorPaginateWithoutCountBuildsACountFreePage(): void
    {
        $request = StubJsonApiRequest::create(['page' => ['number' => '3']]);

        $page = FixedPagePaginator::make(25)->paginateWithoutCount($request, [], hasMore: true);

        self::assertInstanceOf(FixedPagePage::class, $page);
        self::assertNull($page->totalItems);
        self::assertTrue($page->hasMore);
        self::assertSame(3, $page->page);
        self::assertArrayNotHasKey('total', $page->pageMeta());
    }

    #[Test]
    public function offsetPaginatorExposesItsWindowBeforeFetching(): void
    {
        $request = StubJsonApiRequest::create(['page' => ['offset' => '40', 'limit' => '20']]);

        $window = OffsetPaginator::make()->window($request);

        self::assertInstanceOf(OffsetWindow::class, $window);
        self::assertSame(40, $window->offset);
        self::assertSame(20, $window->limit);
    }

    #[Test]
    public function pagePaginatorWindowDerivesOffsetFromPageNumber(): void
    {
        $request = StubJsonApiRequest::create(['page' => ['number' => '3', 'size' => '20']]);

        $window = PagePaginator::make()->window($request);

        self::assertSame(40, $window->offset);
        self::assertSame(20, $window->limit);
    }

    #[Test]
    public function fixedPagePaginatorWindowUsesTheConfiguredSize(): void
    {
        $request = StubJsonApiRequest::create(['page' => ['number' => '4']]);

        $window = FixedPagePaginator::make(25)->window($request);

        self::assertSame(75, $window->offset);
        self::assertSame(25, $window->limit);
    }

    #[Test]
    public function paginateDescribesTheSameWindowItServes(): void
    {
        // window() and paginate() share one normalisation, so for out-of-range
        // input the page meta/links describe exactly the rows the window
        // fetched — never a phantom page 0 or a negative offset.
        $pageZero = StubJsonApiRequest::create(['page' => ['number' => '0', 'size' => '2']]);
        self::assertSame(0, PagePaginator::make()->window($pageZero)->offset);
        self::assertSame(1, PagePaginator::make()->paginate($pageZero, [], 5)->page);

        $negativeOffset = StubJsonApiRequest::create(['page' => ['offset' => '-5', 'limit' => '2']]);
        self::assertSame(0, OffsetPaginator::make()->window($negativeOffset)->offset);
        self::assertSame(0, OffsetPaginator::make()->paginate($negativeOffset, [], 5)->offset);

        $fixedPageZero = StubJsonApiRequest::create(['page' => ['number' => '0']]);
        self::assertSame(0, FixedPagePaginator::make(2)->window($fixedPageZero)->offset);
        self::assertSame(1, FixedPagePaginator::make(2)->paginate($fixedPageZero, [], 5)->page);
    }

    #[Test]
    public function aZeroPageSizeYieldsADegeneratePageNotACrash(): void
    {
        // page[size] is client-controlled: size 0 must render an empty page
        // (no links, lastPage 0), never divide by zero.
        $request = StubJsonApiRequest::create(['page' => ['number' => '1', 'size' => '0']]);
        $page = PagePaginator::make()->paginate($request, [], 5);

        self::assertSame(0, $page->size);
        self::assertSame(0, $page->pageMeta()['lastPage'] ?? null);
        self::assertSame([], \array_filter($page->linkSet('https://api.test/users', '')));

        $fixed = FixedPagePaginator::make(0)->paginate(StubJsonApiRequest::create([]), [], 5);
        self::assertSame(0, $fixed->pageMeta()['lastPage'] ?? null);
    }

    #[Test]
    public function windowsFallBackToDefaultsAndNormaliseGarbage(): void
    {
        $defaults = OffsetPaginator::make()->window(StubJsonApiRequest::create([]));
        self::assertSame(0, $defaults->offset);
        self::assertSame(15, $defaults->limit);

        $garbage = OffsetPaginator::make()->window(
            StubJsonApiRequest::create(['page' => ['offset' => '-5', 'limit' => '-1']]),
        );
        self::assertSame(0, $garbage->offset);
        self::assertSame(0, $garbage->limit);

        $pageZero = PagePaginator::make()->window(StubJsonApiRequest::create(['page' => ['number' => '0']]));
        self::assertSame(0, $pageZero->offset);
        self::assertSame(15, $pageZero->limit);
    }

    #[Test]
    public function pagePaginatorCapsAnOverLargePageSizeAtTheDefaultMax(): void
    {
        // The default cap (100) clamps an abusive page[size] in both the window
        // (what the store fetches) and the rendered page meta — never honoured.
        $request = StubJsonApiRequest::create(['page' => ['number' => '1', 'size' => '1000000']]);

        self::assertSame(100, PagePaginator::make()->window($request)->limit);
        self::assertSame(100, PagePaginator::make()->paginate($request, [], 1000)->size);
        self::assertSame(100, PagePaginator::make()->paginate($request, [], 1000)->pageMeta()['perPage'] ?? null);
    }

    #[Test]
    public function pagePaginatorLeavesAnInRangeSizeUnchanged(): void
    {
        // A size at or below the cap is honoured verbatim; the cap only clamps
        // down, it never raises a smaller request.
        $request = StubJsonApiRequest::create(['page' => ['number' => '1', 'size' => '50']]);

        self::assertSame(50, PagePaginator::make()->window($request)->limit);
        self::assertSame(50, PagePaginator::make()->paginate($request, [], 1000)->size);
    }

    #[Test]
    public function pagePaginatorHonoursACustomMaxPerPage(): void
    {
        $request = StubJsonApiRequest::create(['page' => ['number' => '1', 'size' => '500']]);

        $paginator = PagePaginator::make()->withMaxPerPage(25);

        self::assertSame(25, $paginator->window($request)->limit);
        self::assertSame(25, $paginator->paginate($request, [], 1000)->size);
    }

    #[Test]
    public function pagePaginatorDisablesTheCapWithMaxPerPageZero(): void
    {
        $request = StubJsonApiRequest::create(['page' => ['number' => '1', 'size' => '1000000']]);

        $paginator = PagePaginator::make()->withMaxPerPage(0);

        self::assertSame(1000000, $paginator->window($request)->limit);
        self::assertSame(1000000, $paginator->paginate($request, [], 5_000_000)->size);
    }

    #[Test]
    public function theDefaultPerPageIsUnaffectedByTheCapWhenNoSizeIsSent(): void
    {
        // No page[size] sent: the configured default (below the cap) is used as-is.
        $request = StubJsonApiRequest::create([]);

        self::assertSame(15, PagePaginator::make()->window($request)->limit);
        self::assertSame(15, PagePaginator::make()->paginate($request, [], 1000)->size);
    }

    #[Test]
    public function offsetPaginatorCapsAnOverLargeLimitAtTheDefaultMax(): void
    {
        $request = StubJsonApiRequest::create(['page' => ['offset' => '0', 'limit' => '1000000']]);

        self::assertSame(100, OffsetPaginator::make()->window($request)->limit);
        self::assertSame(100, OffsetPaginator::make()->paginate($request, [], 1000)->limit);
        self::assertSame(100, OffsetPaginator::make()->paginate($request, [], 1000)->pageMeta()['limit'] ?? null);
    }

    #[Test]
    public function offsetPaginatorHonoursAnInRangeOrCustomCappedLimit(): void
    {
        $inRange = StubJsonApiRequest::create(['page' => ['offset' => '0', 'limit' => '40']]);
        self::assertSame(40, OffsetPaginator::make()->window($inRange)->limit);

        $custom = StubJsonApiRequest::create(['page' => ['offset' => '0', 'limit' => '500']]);
        self::assertSame(25, OffsetPaginator::make()->withMaxPerPage(25)->window($custom)->limit);
        self::assertSame(500, OffsetPaginator::make()->withMaxPerPage(0)->window($custom)->limit);
    }

    #[Test]
    #[Group('spec:extensions-and-profiles')]
    public function cursorPaginatorCapsAnOverLargeSize(): void
    {
        $request = StubJsonApiRequest::create(['page' => ['size' => '1000000']]);

        $capped = CursorPaginator::make()->fromBoundaries($request, [], 'a', 'b', hasNext: true, hasPrevious: false);
        self::assertSame(100, $capped->size);

        $uncapped = CursorPaginator::make()->withMaxPerPage(0)->fromBoundaries($request, [], 'a', 'b', hasNext: true, hasPrevious: false);
        self::assertSame(1000000, $uncapped->size);
    }

    #[Test]
    #[Group('spec:extensions-and-profiles')]
    public function cursorPaginatorReadsSizeAndAttachesProfile(): void
    {
        $request = StubJsonApiRequest::create(['page' => ['size' => '10']]);

        $page = CursorPaginator::make()->fromBoundaries($request, [], 'first-cursor', 'last-cursor', hasNext: true, hasPrevious: false);

        self::assertInstanceOf(CursorBasedPage::class, $page);
        self::assertSame(10, $page->size);
        self::assertSame('first-cursor', $page->cursorBefore);
        self::assertSame('last-cursor', $page->cursorAfter);
        self::assertTrue($page->hasNext);
        self::assertFalse($page->hasPrevious);
        self::assertInstanceOf(CursorPaginationProfile::class, $page->profile());
    }

    #[Test]
    #[Group('spec:extensions-and-profiles')]
    public function cursorPageEmitsExactlyTheQueryParamsItsProfileReserves(): void
    {
        // Guards against drift between the cursor paginator's actual page[…] keys
        // and the keywords its profile advertises: the set of page[…] params the
        // page emits across its whole link set must equal the profile's reserved
        // page[…] keywords. If the strategy starts emitting a new param (or renames
        // one) without updating CursorPaginationProfile::keywords() — or vice versa —
        // this fails.
        $request = StubJsonApiRequest::create(['page' => ['size' => '10']]);
        $page = CursorPaginator::make()->fromBoundaries($request, [], 'before-cur', 'after-cur', hasNext: true, hasPrevious: true);

        $emitted = [];
        foreach ($page->linkSet('https://api.test/users', '') as $link) {
            if ($link === null) {
                continue;
            }

            $href = $link->transform('');
            self::assertIsString($href);

            \parse_str((string) \parse_url($href, \PHP_URL_QUERY), $query);
            $pageParams = $query['page'] ?? [];
            self::assertIsArray($pageParams);
            foreach (\array_keys($pageParams) as $key) {
                $emitted['page[' . $key . ']'] = true;
            }
        }

        $reservedPageParams = \array_values(\array_filter(
            $page->profile()?->keywords() ?? [],
            static fn(string $keyword): bool => \str_starts_with($keyword, 'page['),
        ));

        \sort($reservedPageParams);
        $emittedParams = \array_keys($emitted);
        \sort($emittedParams);

        self::assertSame($reservedPageParams, $emittedParams);
    }

    #[Test]
    public function countBasedPaginatorsAreCountFreeByDefault(): void
    {
        self::assertFalse(PagePaginator::make()->wantsCount());
        self::assertFalse(OffsetPaginator::make()->wantsCount());
        self::assertFalse(FixedPagePaginator::make()->wantsCount());
    }

    #[Test]
    public function withCountFlipsTheCountFlagOnEachCountBasedPaginator(): void
    {
        self::assertTrue(PagePaginator::make()->withCount()->wantsCount());
        self::assertTrue(OffsetPaginator::make()->withCount()->wantsCount());
        self::assertTrue(FixedPagePaginator::make()->withCount()->wantsCount());

        // withCount() is immutable: it returns a new instance, leaving the original
        // count-free.
        $base = PagePaginator::make();
        self::assertFalse($base->wantsCount());
        self::assertNotSame($base, $base->withCount());
    }

    #[Test]
    public function everyPagePaginatorCloneKeepsTheCountFlag(): void
    {
        $counted = PagePaginator::make()->withCount();

        self::assertTrue($counted->withPageKey('p')->wantsCount());
        self::assertTrue($counted->withPerPageKey('per')->wantsCount());
        self::assertTrue($counted->withDefaultPage(2)->wantsCount());
        self::assertTrue($counted->withDefaultPerPage(30)->wantsCount());
        self::assertTrue($counted->withMaxPerPage(50)->wantsCount());
    }

    #[Test]
    public function everyOffsetPaginatorCloneKeepsTheCountFlag(): void
    {
        $counted = OffsetPaginator::make()->withCount();

        self::assertTrue($counted->withOffsetKey('o')->wantsCount());
        self::assertTrue($counted->withLimitKey('l')->wantsCount());
        self::assertTrue($counted->withDefaultOffset(10)->wantsCount());
        self::assertTrue($counted->withDefaultLimit(30)->wantsCount());
        self::assertTrue($counted->withMaxPerPage(50)->wantsCount());
    }

    #[Test]
    public function everyFixedPagePaginatorCloneKeepsTheCountFlag(): void
    {
        $counted = FixedPagePaginator::make()->withCount();

        self::assertTrue($counted->withSize(20)->wantsCount());
        self::assertTrue($counted->withPageKey('p')->wantsCount());
        self::assertTrue($counted->withDefaultPage(2)->wantsCount());
    }

    #[Test]
    public function cursorPaginatorIsAlwaysCountFree(): void
    {
        // The cursor strategy never counts (and, by design, has no withCount()
        // counterpart — verified by the static type checker, not at runtime).
        self::assertFalse(CursorPaginator::make()->wantsCount());
    }
}
