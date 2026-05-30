<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Request\Pagination;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Request\Pagination\CursorBasedPagination;
use haddowg\JsonApi\Request\Pagination\FixedCursorBasedPagination;
use haddowg\JsonApi\Request\Pagination\FixedPageBasedPagination;
use haddowg\JsonApi\Request\Pagination\OffsetBasedPagination;
use haddowg\JsonApi\Request\Pagination\PageBasedPagination;
use haddowg\JsonApi\Request\Pagination\PaginationFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Rewrites from yin:
 * - Replaced yin's Laminas-Diactoros-backed JsonApiRequest with a PHPUnit mock of JsonApiRequestInterface,
 *   since the test layer has no dependency on any PSR-7 implementation.
 * - Dropped DefaultExceptionFactory and JsonDeserializer constructor args (not part of our API).
 * - PHPUnit 12 attributes only (#[Test]) — no docblock annotations.
 * - self::assert* + assertEquals on value-object instances (readonly classes support structural equality).
 */
final class PaginationFactoryTest extends TestCase
{
    #[Test]
    public function createFixedPageBasedPagination(): void
    {
        $factory = $this->createFactoryWithPaginationParams(['number' => 1]);

        $pagination = $factory->createFixedPageBasedPagination();

        self::assertEquals(new FixedPageBasedPagination(1), $pagination);
    }

    #[Test]
    public function createPageBasedPagination(): void
    {
        $factory = $this->createFactoryWithPaginationParams(['number' => 1, 'size' => 10]);

        $pagination = $factory->createPageBasedPagination();

        self::assertEquals(new PageBasedPagination(1, 10), $pagination);
    }

    #[Test]
    public function createOffsetBasedPagination(): void
    {
        $factory = $this->createFactoryWithPaginationParams(['offset' => 1, 'limit' => 10]);

        $pagination = $factory->createOffsetBasedPagination();

        self::assertEquals(new OffsetBasedPagination(1, 10), $pagination);
    }

    #[Test]
    public function createCursorBasedPagination(): void
    {
        $factory = $this->createFactoryWithPaginationParams(['cursor' => 'abc', 'size' => 10]);

        $pagination = $factory->createCursorBasedPagination();

        self::assertEquals(new CursorBasedPagination('abc', 10), $pagination);
    }

    #[Test]
    public function createFixedCursorBasedPagination(): void
    {
        $factory = $this->createFactoryWithPaginationParams(['cursor' => 'abc']);

        $pagination = $factory->createFixedCursorBasedPagination();

        self::assertEquals(new FixedCursorBasedPagination('abc'), $pagination);
    }

    /**
     * @param array<string, mixed> $paginationParams
     */
    private function createFactoryWithPaginationParams(array $paginationParams): PaginationFactory
    {
        $request = $this->createStub(JsonApiRequestInterface::class);
        $request->method('getPagination')->willReturn($paginationParams);

        return new PaginationFactory($request);
    }
}
