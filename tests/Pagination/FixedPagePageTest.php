<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Pagination;

use haddowg\JsonApi\Pagination\FixedPagePage;
use haddowg\JsonApi\Schema\Link\Link;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:pagination')]
final class FixedPagePageTest extends TestCase
{
    private const string URI = 'https://example.com/api/users';

    #[Test]
    public function emitsPageNumberLinksWithoutEchoingSize(): void
    {
        $page = new FixedPagePage([], totalItems: 50, page: 2, size: 10);

        $links = $page->linkSet(self::URI, '');

        self::assertSame(self::URI . '?page%5Bnumber%5D=2', $this->href($links['self']));
        self::assertSame(self::URI . '?page%5Bnumber%5D=1', $this->href($links['first']));
        self::assertSame(self::URI . '?page%5Bnumber%5D=1', $this->href($links['prev']));
        self::assertSame(self::URI . '?page%5Bnumber%5D=3', $this->href($links['next']));
        self::assertSame(self::URI . '?page%5Bnumber%5D=5', $this->href($links['last']));
    }

    #[Test]
    public function exposesPageMeta(): void
    {
        $page = new FixedPagePage([], totalItems: 50, page: 2, size: 10);

        self::assertSame(['currentPage' => 2, 'total' => 50, 'lastPage' => 5], $page->pageMeta());
    }

    #[Test]
    public function countFreeMiddlePageOmitsLastAndEmitsNextFromHasMore(): void
    {
        $page = new FixedPagePage([], totalItems: null, page: 2, size: 10, hasMore: true);

        $links = $page->linkSet(self::URI, '');

        self::assertSame(self::URI . '?page%5Bnumber%5D=2', $this->href($links['self']));
        self::assertSame(self::URI . '?page%5Bnumber%5D=1', $this->href($links['first']));
        self::assertSame(self::URI . '?page%5Bnumber%5D=1', $this->href($links['prev']));
        self::assertSame(self::URI . '?page%5Bnumber%5D=3', $this->href($links['next']));
        self::assertNull($links['last']);
    }

    #[Test]
    public function countFreeLastPageOmitsNextWhenNoMore(): void
    {
        $page = new FixedPagePage([], totalItems: null, page: 2, size: 10, hasMore: false);

        $links = $page->linkSet(self::URI, '');

        self::assertNotNull($links['prev']);
        self::assertNull($links['next']);
        self::assertNull($links['last']);
    }

    #[Test]
    public function countFreePageMetaOmitsTotalAndLastPage(): void
    {
        $page = new FixedPagePage([], totalItems: null, page: 2, size: 10, hasMore: true);

        self::assertSame(['currentPage' => 2], $page->pageMeta());
    }

    /**
     * @param Link|null $link
     */
    private function href(mixed $link): string
    {
        self::assertInstanceOf(Link::class, $link);

        $transformed = $link->transform('');
        self::assertIsString($transformed);

        return $transformed;
    }
}
