<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Request\Pagination;

use haddowg\JsonApi\Request\Pagination\FixedPageBasedPagination;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Rewrites from yin:
 * - Removed getPage() test; replaced with direct public-property access ($pagination->page).
 * - PHPUnit 12 attributes only (#[Test], #[Group]) — no docblock annotations.
 * - self::assert* instead of $this->assert*.
 */
final class FixedPageBasedPaginationTest extends TestCase
{
    #[Test]
    #[Group('spec:pagination')]
    public function fromPaginationQueryParams(): void
    {
        $pagination = FixedPageBasedPagination::fromPaginationQueryParams(['number' => 1]);

        self::assertEquals(1, $pagination->page);
    }

    #[Test]
    #[Group('spec:pagination')]
    public function fromPaginationQueryParamsWhenMissing(): void
    {
        $pagination = FixedPageBasedPagination::fromPaginationQueryParams([], 1);

        self::assertEquals(1, $pagination->page);
    }

    #[Test]
    #[Group('spec:pagination')]
    public function fromPaginationQueryParamsWhenEmpty(): void
    {
        $pagination = FixedPageBasedPagination::fromPaginationQueryParams(['number' => ''], 1);

        self::assertEquals(1, $pagination->page);
    }

    #[Test]
    #[Group('spec:pagination')]
    public function fromPaginationQueryParamsWhenZero(): void
    {
        $pagination = FixedPageBasedPagination::fromPaginationQueryParams(['number' => '0'], 1);

        self::assertEquals(0, $pagination->page);
    }

    #[Test]
    #[Group('spec:pagination')]
    public function fromPaginationQueryParamsWhenNonNumeric(): void
    {
        $pagination = FixedPageBasedPagination::fromPaginationQueryParams(['number' => 'abc'], 1);

        self::assertEquals(1, $pagination->page);
    }

    #[Test]
    public function pagePropertyAccessible(): void
    {
        $pagination = new FixedPageBasedPagination(1);

        self::assertEquals(1, $pagination->page);
    }

    #[Test]
    public function getPaginationQueryString(): void
    {
        $queryString = FixedPageBasedPagination::getPaginationQueryString(1);

        self::assertEquals('page%5Bnumber%5D=1', $queryString);
    }
}
