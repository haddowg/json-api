<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Pagination;

use haddowg\JsonApi\Pagination\OffsetBasedPage;
use haddowg\JsonApi\Schema\Link\Link;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:pagination')]
final class OffsetBasedPageTest extends TestCase
{
    private const string URI = 'https://example.com/api/users';

    #[Test]
    public function emitsFullLinkSetForAMiddleWindow(): void
    {
        $page = new OffsetBasedPage([], totalItems: 50, offset: 10, limit: 10);

        $links = $page->linkSet(self::URI, '');

        self::assertSame(self::URI . '?page%5Boffset%5D=10&page%5Blimit%5D=10', $this->href($links['self']));
        self::assertSame(self::URI . '?page%5Boffset%5D=0&page%5Blimit%5D=10', $this->href($links['first']));
        self::assertSame(self::URI . '?page%5Boffset%5D=0&page%5Blimit%5D=10', $this->href($links['prev']));
        self::assertSame(self::URI . '?page%5Boffset%5D=20&page%5Blimit%5D=10', $this->href($links['next']));
        self::assertSame(self::URI . '?page%5Boffset%5D=40&page%5Blimit%5D=10', $this->href($links['last']));
    }

    #[Test]
    public function omitsPrevAtStartAndNextAtEnd(): void
    {
        $start = new OffsetBasedPage([], totalItems: 50, offset: 0, limit: 10);
        self::assertNull($start->linkSet(self::URI, '')['prev']);
        self::assertNotNull($start->linkSet(self::URI, '')['next']);

        $end = new OffsetBasedPage([], totalItems: 50, offset: 40, limit: 10);
        self::assertNotNull($end->linkSet(self::URI, '')['prev']);
        self::assertNull($end->linkSet(self::URI, '')['next']);
    }

    #[Test]
    public function emitsNoLinksWhenEmpty(): void
    {
        $page = new OffsetBasedPage([], totalItems: 0, offset: 0, limit: 10);

        self::assertSame(
            ['self' => null, 'first' => null, 'prev' => null, 'next' => null, 'last' => null],
            $page->linkSet(self::URI, ''),
        );
    }

    #[Test]
    public function exposesPageMeta(): void
    {
        $page = new OffsetBasedPage([], totalItems: 50, offset: 10, limit: 10);

        self::assertSame(
            ['offset' => 10, 'limit' => 10, 'from' => 11, 'to' => 20, 'total' => 50],
            $page->pageMeta(),
        );
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
