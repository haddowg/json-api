<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Request\Pagination;

use haddowg\JsonApi\Request\Pagination\PageBasedPagination;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Rewrites from yin:
 * - Removed getPage()/getSize() tests; replaced with direct public-property access ($pagination->page, ->size).
 * - PHPUnit 12 attributes only (#[Test], #[Group]) — no docblock annotations.
 * - self::assert* instead of $this->assert*.
 */
final class PageBasedPaginationTest extends TestCase
{
    #[Test]
    #[Group('spec:pagination')]
    public function fromPaginationQueryParams(): void
    {
        $pagination = PageBasedPagination::fromPaginationQueryParams(['number' => 1, 'size' => '10']);

        self::assertEquals(1, $pagination->page);
        self::assertEquals(10, $pagination->size);
    }

    #[Test]
    #[Group('spec:pagination')]
    public function fromPaginationQueryParamsWhenMissing(): void
    {
        $pagination = PageBasedPagination::fromPaginationQueryParams([], 1, 10);

        self::assertEquals(1, $pagination->page);
        self::assertEquals(10, $pagination->size);
    }

    #[Test]
    #[Group('spec:pagination')]
    public function fromPaginationQueryParamsWhenEmpty(): void
    {
        $pagination = PageBasedPagination::fromPaginationQueryParams(['number' => '', 'size' => ''], 1, 10);

        self::assertEquals(1, $pagination->page);
        self::assertEquals(10, $pagination->size);
    }

    #[Test]
    #[Group('spec:pagination')]
    public function fromPaginationQueryParamsWhenZero(): void
    {
        $pagination = PageBasedPagination::fromPaginationQueryParams(['number' => '0', 'size' => '0'], 1, 10);

        self::assertEquals(0, $pagination->page);
        self::assertEquals(0, $pagination->size);
    }

    #[Test]
    #[Group('spec:pagination')]
    public function fromPaginationQueryParamsWhenNonNumeric(): void
    {
        $pagination = PageBasedPagination::fromPaginationQueryParams(['number' => 'abc', 'size' => 'abc'], 1, 10);

        self::assertEquals(1, $pagination->page);
        self::assertEquals(10, $pagination->size);
    }

    #[Test]
    public function pagePropertyAccessible(): void
    {
        $pagination = new PageBasedPagination(1, 10);

        self::assertEquals(1, $pagination->page);
    }

    #[Test]
    public function sizePropertyAccessible(): void
    {
        $pagination = new PageBasedPagination(1, 10);

        self::assertEquals(10, $pagination->size);
    }

    #[Test]
    public function getPaginationQueryString(): void
    {
        $queryString = PageBasedPagination::getPaginationQueryString(1, 10);

        self::assertEquals('page%5Bnumber%5D=1&page%5Bsize%5D=10', $queryString);
    }
}
