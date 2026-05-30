<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Request\Pagination;

use haddowg\JsonApi\Request\Pagination\CursorBasedPagination;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Rewrites from yin:
 * - Removed getCursor()/getSize() tests; replaced with direct public-property access ($pagination->cursor, ->size).
 * - PHPUnit 12 attributes only (#[Test], #[Group]) — no docblock annotations.
 * - self::assert* instead of $this->assert*.
 */
final class CursorBasedPaginationTest extends TestCase
{
    #[Test]
    #[Group('spec:pagination')]
    public function fromPaginationQueryParams(): void
    {
        $pagination = CursorBasedPagination::fromPaginationQueryParams(['cursor' => 'abc', 'size' => '10']);

        self::assertEquals('abc', $pagination->cursor);
        self::assertEquals(10, $pagination->size);
    }

    #[Test]
    #[Group('spec:pagination')]
    public function fromMissingPaginationQueryParams(): void
    {
        $pagination = CursorBasedPagination::fromPaginationQueryParams([], 'abc', 10);

        self::assertEquals('abc', $pagination->cursor);
        self::assertEquals(10, $pagination->size);
    }

    #[Test]
    #[Group('spec:pagination')]
    public function fromEmptyPaginationQueryParams(): void
    {
        $pagination = CursorBasedPagination::fromPaginationQueryParams(['cursor' => '', 'size' => ''], 'abc', 10);

        self::assertEquals('', $pagination->cursor);
        self::assertEquals(10, $pagination->size);
    }

    #[Test]
    public function cursorPropertyAccessible(): void
    {
        $pagination = new CursorBasedPagination('abc', 10);

        self::assertEquals('abc', $pagination->cursor);
        self::assertEquals(10, $pagination->size);
    }

    #[Test]
    public function getPaginationQueryString(): void
    {
        $queryString = CursorBasedPagination::getPaginationQueryString('abc', 10);

        self::assertEquals('page%5Bcursor%5D=abc&page%5Bsize%5D=10', $queryString);
    }
}
