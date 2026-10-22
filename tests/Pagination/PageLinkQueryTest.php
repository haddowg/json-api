<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Pagination;

use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Pagination\FixedPagePaginator;
use haddowg\JsonApi\Pagination\MultiPaginator;
use haddowg\JsonApi\Pagination\OffsetPaginator;
use haddowg\JsonApi\Pagination\PageInterface;
use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The `page` query each pagination link carries: a renamed key appears under its
 * configured name, and a strategy a menu selected over its default keeps `page[kind]`.
 */
#[Group('pagination')]
final class PageLinkQueryTest extends TestCase
{
    private const string URI = 'https://api.test/tracks';

    #[Test]
    public function aRenamedPageStrategyLinksUnderItsOwnKeys(): void
    {
        $links = $this->pageLinks(
            PagePaginator::make()->withPageKey('p')->withPerPageKey('per'),
            ['p' => '2', 'per' => '10'],
        );

        self::assertSame(['p' => '1', 'per' => '10'], $links['first']);
        self::assertSame(['p' => '3', 'per' => '10'], $links['next']);
    }

    #[Test]
    public function aRenamedOffsetStrategyLinksUnderItsOwnKeys(): void
    {
        $links = $this->pageLinks(
            OffsetPaginator::make()->withOffsetKey('skip')->withLimitKey('take'),
            ['skip' => '10', 'take' => '10'],
        );

        self::assertSame(['skip' => '20', 'take' => '10'], $links['next']);
    }

    #[Test]
    public function aRenamedFixedStrategyLinksUnderItsOwnKey(): void
    {
        $links = $this->pageLinks(FixedPagePaginator::make(10)->withPageKey('p'), ['p' => '2']);

        self::assertSame(['p' => '3'], $links['next']);
    }

    #[Test]
    public function aRenamedCursorSizeLinksUnderItsOwnKey(): void
    {
        $request = StubJsonApiRequest::create(['page' => ['limit' => '5']]);
        $page = CursorPaginator::make()->withSizeKey('limit')
            ->fromBoundaries($request, [], 'b', 'a', hasNext: true, hasPrevious: true);

        $links = $this->queries($page);

        self::assertSame(['limit' => '5'], $links['first']);
        self::assertSame(['after' => 'a', 'limit' => '5'], $links['next']);
        self::assertSame(['before' => 'b', 'limit' => '5'], $links['prev']);
    }

    #[Test]
    public function aStrategyChosenByKindOverTheDefaultKeepsItsKind(): void
    {
        $links = $this->pageLinks($this->menu('cursor'), ['kind' => 'page', 'number' => '2', 'size' => '10']);

        self::assertSame(['kind' => 'page', 'number' => '1', 'size' => '10'], $links['first']);
        self::assertSame(['kind' => 'page', 'number' => '3', 'size' => '10'], $links['next']);
    }

    #[Test]
    public function aStrategyChosenByAUniqueKeyKeepsItsKindOnSharedKeyLinks(): void
    {
        $request = StubJsonApiRequest::create(['page' => ['after' => 'x', 'size' => '5']]);
        $cursor = $this->menu('page')->resolve($request);
        self::assertInstanceOf(CursorPaginator::class, $cursor);

        $links = $this->queries($cursor->fromBoundaries($request, [], 'b', 'a', hasNext: true, hasPrevious: true));

        // `first` carries only the shared `size`, so without `kind` it would select the page default.
        self::assertSame(['kind' => 'cursor', 'size' => '5'], $links['first']);
        self::assertSame(['kind' => 'cursor', 'after' => 'a', 'size' => '5'], $links['next']);
    }

    #[Test]
    public function theDefaultStrategyLinksWithoutAKind(): void
    {
        self::assertSame(
            ['number' => '3', 'size' => '10'],
            $this->pageLinks($this->menu('page'), ['number' => '2', 'size' => '10'])['next'],
        );
        self::assertSame(
            ['number' => '3', 'size' => '10'],
            $this->pageLinks($this->menu('page'), ['kind' => 'page', 'number' => '2', 'size' => '10'])['next'],
        );
    }

    #[Test]
    public function aSingleStrategyOutsideAMenuLinksWithoutAKind(): void
    {
        self::assertSame(
            ['number' => '3', 'size' => '10'],
            $this->pageLinks(PagePaginator::make(), ['number' => '2', 'size' => '10'])['next'],
        );
    }

    private function menu(string $default): MultiPaginator
    {
        return MultiPaginator::make(PagePaginator::make(), CursorPaginator::make(), OffsetPaginator::make())->default($default);
    }

    /**
     * @param array<string, string> $page
     *
     * @return array<string, array<string, mixed>>
     */
    private function pageLinks(PaginatorInterface $paginator, array $page): array
    {
        $request = StubJsonApiRequest::create(['page' => $page]);

        return $this->queries($paginator->paginate($request, \range(1, 10), 50));
    }

    /**
     * @param PageInterface<mixed> $page
     *
     * @return array<string, array<string, mixed>>
     */
    private function queries(PageInterface $page): array
    {
        $queries = [];
        foreach ($page->linkSet(self::URI, '') as $rel => $link) {
            if ($link === null) {
                continue;
            }
            $href = $link->transform('');
            self::assertIsString($href);
            \parse_str((string) \parse_url($href, \PHP_URL_QUERY), $query);
            $page = $query['page'] ?? [];
            self::assertIsArray($page);
            /** @var array<string, mixed> $page */
            $queries[$rel] = $page;
        }

        return $queries;
    }
}
