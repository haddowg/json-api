<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Pagination;

use haddowg\JsonApi\Pagination\CursorBasedPage;
use haddowg\JsonApi\Pagination\CursorPaginationProfile;
use haddowg\JsonApi\Schema\Link\Link;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:pagination')]
#[Group('spec:extensions-and-profiles')]
final class CursorBasedPageTest extends TestCase
{
    private const string URI = 'https://example.com/api/users';

    #[Test]
    public function emitsFirstPrevNextButNeverLast(): void
    {
        $page = new CursorBasedPage(
            items: [],
            size: 10,
            cursorBefore: 'cur-first',
            cursorAfter: 'cur-last',
            hasNext: true,
            hasPrevious: true,
        );

        $links = $page->linkSet(self::URI, '');

        self::assertSame(self::URI . '?page%5Bsize%5D=10', $this->href($links['first']));
        self::assertSame(self::URI . '?page%5Bbefore%5D=cur-first&page%5Bsize%5D=10', $this->href($links['prev']));
        self::assertSame(self::URI . '?page%5Bafter%5D=cur-last&page%5Bsize%5D=10', $this->href($links['next']));
        self::assertNull($links['last'], 'cursor pagination must not emit a last link');
        self::assertArrayHasKey('last', $links);
    }

    #[Test]
    public function omitsPrevAndNextWhenNoNeighbours(): void
    {
        $page = new CursorBasedPage(
            items: [],
            size: 10,
            cursorBefore: 'a',
            cursorAfter: 'b',
            hasNext: false,
            hasPrevious: false,
        );

        $links = $page->linkSet(self::URI, '');

        self::assertNull($links['prev']);
        self::assertNull($links['next']);
        self::assertNotNull($links['first']);
    }

    #[Test]
    public function exposesPageMetaAndProfile(): void
    {
        $profile = new CursorPaginationProfile();
        $page = new CursorBasedPage(
            items: [],
            size: 10,
            cursorBefore: 'a',
            cursorAfter: 'b',
            hasNext: true,
            hasPrevious: false,
            profile: $profile,
        );

        self::assertSame(['perPage' => 10, 'hasMore' => true], $page->pageMeta());
        self::assertSame($profile, $page->profile());
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
