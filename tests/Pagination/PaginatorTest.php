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
    #[Group('spec:extensions-and-profiles')]
    public function cursorPaginatorReadsSizeAndAttachesProfile(): void
    {
        $request = StubJsonApiRequest::create(['page' => ['size' => '10']]);

        $page = CursorPaginator::make()->paginate($request, [], 'first-cursor', 'last-cursor', hasNext: true, hasPrevious: false);

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
        $page = CursorPaginator::make()->paginate($request, [], 'before-cur', 'after-cur', hasNext: true, hasPrevious: true);

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
}
