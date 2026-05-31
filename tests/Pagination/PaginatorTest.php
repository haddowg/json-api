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
