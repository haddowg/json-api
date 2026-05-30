<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Request\Pagination;

use haddowg\JsonApi\Request\Pagination\FixedCursorBasedPagination;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Rewrites from yin:
 * - Removed getCursor() test; replaced with direct public-property access ($pagination->cursor).
 * - PHPUnit 12 attributes only (#[Test], #[Group]) — no docblock annotations.
 * - self::assert* instead of $this->assert*.
 */
final class FixedCursorBasedPaginationTest extends TestCase
{
    #[Test]
    #[Group('spec:pagination')]
    public function fromPaginationQueryParams(): void
    {
        $pagination = FixedCursorBasedPagination::fromPaginationQueryParams(['cursor' => 'abc']);

        self::assertEquals('abc', $pagination->cursor);
    }

    #[Test]
    #[Group('spec:pagination')]
    public function fromMissingPaginationQueryParams(): void
    {
        $pagination = FixedCursorBasedPagination::fromPaginationQueryParams([], 'abc');

        self::assertEquals('abc', $pagination->cursor);
    }

    #[Test]
    #[Group('spec:pagination')]
    public function fromEmptyPaginationQueryParams(): void
    {
        $pagination = FixedCursorBasedPagination::fromPaginationQueryParams(['cursor' => ''], 'abc');

        self::assertEquals('', $pagination->cursor);
    }

    #[Test]
    public function cursorPropertyAccessible(): void
    {
        $pagination = new FixedCursorBasedPagination('abc');

        self::assertEquals('abc', $pagination->cursor);
    }

    #[Test]
    public function getPaginationQueryString(): void
    {
        $queryString = FixedCursorBasedPagination::getPaginationQueryString('abc');

        self::assertEquals('page%5Bcursor%5D=abc', $queryString);
    }
}
