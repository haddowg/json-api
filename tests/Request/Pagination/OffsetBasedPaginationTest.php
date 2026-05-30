<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Request\Pagination;

use haddowg\JsonApi\Request\Pagination\OffsetBasedPagination;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Rewrites from yin:
 * - Removed getOffset()/getLimit() tests; replaced with direct public-property access ($pagination->offset, ->limit).
 * - PHPUnit 12 attributes only (#[Test], #[Group]) — no docblock annotations.
 * - self::assert* instead of $this->assert*.
 */
final class OffsetBasedPaginationTest extends TestCase
{
    #[Test]
    #[Group('spec:pagination')]
    public function fromPaginationQueryParams(): void
    {
        $pagination = OffsetBasedPagination::fromPaginationQueryParams(['offset' => 1, 'limit' => 10]);

        self::assertEquals(1, $pagination->offset);
        self::assertEquals(10, $pagination->limit);
    }

    #[Test]
    #[Group('spec:pagination')]
    public function fromPaginationQueryParamsWhenMissing(): void
    {
        $pagination = OffsetBasedPagination::fromPaginationQueryParams([], 1, 10);

        self::assertEquals(1, $pagination->offset);
        self::assertEquals(10, $pagination->limit);
    }

    #[Test]
    #[Group('spec:pagination')]
    public function fromPaginationQueryParamsWhenEmpty(): void
    {
        $pagination = OffsetBasedPagination::fromPaginationQueryParams(['offset' => '', 'limit' => ''], 1, 10);

        self::assertEquals(1, $pagination->offset);
        self::assertEquals(10, $pagination->limit);
    }

    #[Test]
    #[Group('spec:pagination')]
    public function fromPaginationQueryParamsWhenZero(): void
    {
        $pagination = OffsetBasedPagination::fromPaginationQueryParams(['offset' => '0', 'limit' => '0'], 1, 10);

        self::assertEquals(0, $pagination->offset);
        self::assertEquals(0, $pagination->limit);
    }

    #[Test]
    #[Group('spec:pagination')]
    public function fromPaginationQueryParamsWhenNonNumeric(): void
    {
        $pagination = OffsetBasedPagination::fromPaginationQueryParams(['offset' => 'abc', 'limit' => 'abc'], 1, 10);

        self::assertEquals(1, $pagination->offset);
        self::assertEquals(10, $pagination->limit);
    }

    #[Test]
    public function offsetPropertyAccessible(): void
    {
        $pagination = new OffsetBasedPagination(1, 10);

        self::assertEquals(1, $pagination->offset);
    }

    #[Test]
    public function limitPropertyAccessible(): void
    {
        $pagination = new OffsetBasedPagination(1, 10);

        self::assertEquals(10, $pagination->limit);
    }

    #[Test]
    public function getPaginationQueryString(): void
    {
        $queryString = OffsetBasedPagination::getPaginationQueryString(1, 10);

        self::assertEquals('page%5Boffset%5D=1&page%5Blimit%5D=10', $queryString);
    }
}
