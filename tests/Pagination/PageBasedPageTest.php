<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Pagination;

use haddowg\JsonApi\Pagination\PageBasedPage;
use haddowg\JsonApi\Schema\Link\Link;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:pagination')]
final class PageBasedPageTest extends TestCase
{
    private const string URI = 'https://example.com/api/users';

    #[Test]
    public function emitsFullLinkSetForAMiddlePage(): void
    {
        $page = new PageBasedPage(['a', 'b'], totalItems: 50, page: 2, size: 10);

        $links = $page->linkSet(self::URI, '');

        self::assertSame(self::URI . '?page%5Bnumber%5D=2&page%5Bsize%5D=10', $this->href($links['self']));
        self::assertSame(self::URI . '?page%5Bnumber%5D=1&page%5Bsize%5D=10', $this->href($links['first']));
        self::assertSame(self::URI . '?page%5Bnumber%5D=1&page%5Bsize%5D=10', $this->href($links['prev']));
        self::assertSame(self::URI . '?page%5Bnumber%5D=3&page%5Bsize%5D=10', $this->href($links['next']));
        self::assertSame(self::URI . '?page%5Bnumber%5D=5&page%5Bsize%5D=10', $this->href($links['last']));
    }

    #[Test]
    public function omitsPrevOnFirstPageAndNextOnLastPage(): void
    {
        $first = new PageBasedPage([], totalItems: 50, page: 1, size: 10);
        self::assertNull($first->linkSet(self::URI, '')['prev']);
        self::assertNotNull($first->linkSet(self::URI, '')['next']);

        $last = new PageBasedPage([], totalItems: 50, page: 5, size: 10);
        self::assertNotNull($last->linkSet(self::URI, '')['prev']);
        self::assertNull($last->linkSet(self::URI, '')['next']);
    }

    #[Test]
    public function emitsNoLinksWhenEmptyOrInvalid(): void
    {
        $page = new PageBasedPage([], totalItems: 0, page: 1, size: 10);

        self::assertSame(
            ['self' => null, 'first' => null, 'prev' => null, 'next' => null, 'last' => null],
            $page->linkSet(self::URI, ''),
        );
    }

    #[Test]
    public function preservesUnrelatedQueryParams(): void
    {
        $page = new PageBasedPage([], totalItems: 30, page: 1, size: 10);

        $links = $page->linkSet(self::URI . '?filter[active]=1', '');

        self::assertSame(
            self::URI . '?filter%5Bactive%5D=1&page%5Bnumber%5D=2&page%5Bsize%5D=10',
            $this->href($links['next']),
        );
    }

    #[Test]
    public function exposesPageMeta(): void
    {
        $page = new PageBasedPage([], totalItems: 50, page: 2, size: 10);

        self::assertSame(
            ['currentPage' => 2, 'perPage' => 10, 'from' => 11, 'to' => 20, 'total' => 50, 'lastPage' => 5],
            $page->pageMeta(),
        );
    }

    #[Test]
    public function iteratesOverItems(): void
    {
        $page = new PageBasedPage(['a', 'b', 'c'], totalItems: 3, page: 1, size: 10);

        self::assertSame(['a', 'b', 'c'], \iterator_to_array($page));
    }

    #[Test]
    public function hasNoProfile(): void
    {
        $page = new PageBasedPage([], totalItems: 0, page: 1, size: 10);

        self::assertNull($page->profile());
    }

    private function href(mixed $link): string
    {
        self::assertInstanceOf(Link::class, $link);

        $transformed = $link->transform('');
        self::assertIsString($transformed);

        return $transformed;
    }
}
