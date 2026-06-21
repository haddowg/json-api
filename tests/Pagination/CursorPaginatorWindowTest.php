<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Pagination;

use haddowg\JsonApi\Exception\CursorMalformed;
use haddowg\JsonApi\Pagination\CursorBoundary;
use haddowg\JsonApi\Pagination\CursorCodec;
use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Pagination\CursorWindow;
use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:pagination')]
#[Group('spec:extensions-and-profiles')]
final class CursorPaginatorWindowTest extends TestCase
{
    #[Test]
    public function isAPaginator(): void
    {
        self::assertInstanceOf(PaginatorInterface::class, CursorPaginator::make());
    }

    #[Test]
    public function windowReadsPageSizeWithNoBoundariesWhenNoCursorSupplied(): void
    {
        $request = StubJsonApiRequest::create(['page' => ['size' => '20']]);

        $window = CursorPaginator::make()->window($request);

        self::assertInstanceOf(CursorWindow::class, $window);
        self::assertSame(20, $window->limit);
        self::assertNull($window->after);
        self::assertNull($window->before);
    }

    #[Test]
    public function windowFallsBackToTheDefaultSize(): void
    {
        $request = StubJsonApiRequest::create([]);

        $window = CursorPaginator::make()->withDefaultSize(25)->window($request);

        self::assertSame(25, $window->limit);
    }

    #[Test]
    public function windowCapsAnOverLargeSizeAtMaxPerPage(): void
    {
        $request = StubJsonApiRequest::create(['page' => ['size' => '1000000']]);

        self::assertSame(100, CursorPaginator::make()->window($request)->limit);
        self::assertSame(1000000, CursorPaginator::make()->withMaxPerPage(0)->window($request)->limit);
    }

    #[Test]
    public function windowFloorsAZeroOrNegativeSizeToOne(): void
    {
        self::assertSame(1, CursorPaginator::make()->window(StubJsonApiRequest::create(['page' => ['size' => '0']]))->limit);
        self::assertSame(1, CursorPaginator::make()->window(StubJsonApiRequest::create(['page' => ['size' => '-5']]))->limit);
    }

    #[Test]
    public function fromBoundariesAdvertisesTheSameSizeTheWindowFetched(): void
    {
        foreach (['0', '-5', '20', '1000000'] as $requested) {
            $request = StubJsonApiRequest::create(['page' => ['size' => $requested]]);
            $paginator = CursorPaginator::make();

            $window = $paginator->window($request);
            $page = $paginator->fromBoundaries($request, [], '', '', false, false);

            self::assertSame($window->limit, $page->size, \sprintf('page[size]=%s: window limit and page size diverge', $requested));
        }
    }

    #[Test]
    public function windowDecodesTheAfterCursor(): void
    {
        $token = (new CursorCodec())->encode(new CursorBoundary(['name' => 'Ada', 'id' => 7], pointsToNextItems: true));
        $request = StubJsonApiRequest::create(['page' => ['size' => '5', 'after' => $token]]);

        $window = CursorPaginator::make()->window($request);

        self::assertInstanceOf(CursorBoundary::class, $window->after);
        self::assertSame(['name' => 'Ada', 'id' => 7], $window->after->values);
        self::assertTrue($window->after->pointsToNextItems);
        self::assertNull($window->before);
    }

    #[Test]
    public function windowDecodesTheBeforeCursor(): void
    {
        $token = (new CursorCodec())->encode(new CursorBoundary(['id' => 3], pointsToNextItems: false));
        $request = StubJsonApiRequest::create(['page' => ['before' => $token]]);

        $window = CursorPaginator::make()->window($request);

        self::assertInstanceOf(CursorBoundary::class, $window->before);
        self::assertFalse($window->before->pointsToNextItems);
        self::assertNull($window->after);
    }

    #[Test]
    public function windowThrowsMalformedCursorOnAGarbageAfterToken(): void
    {
        $request = StubJsonApiRequest::create(['page' => ['after' => 'not valid base64!!']]);

        $this->expectException(CursorMalformed::class);

        try {
            CursorPaginator::make()->window($request);
        } catch (CursorMalformed $e) {
            self::assertSame('page[after]', $e->parameter);

            throw $e;
        }
    }

    #[Test]
    public function windowThrowsMalformedCursorOnAGarbageBeforeToken(): void
    {
        $request = StubJsonApiRequest::create(['page' => ['before' => 'not valid base64!!']]);

        $this->expectException(CursorMalformed::class);

        try {
            CursorPaginator::make()->window($request);
        } catch (CursorMalformed $e) {
            self::assertSame('page[before]', $e->parameter);

            throw $e;
        }
    }

    #[Test]
    public function windowTreatsAnEmptyCursorAsAbsent(): void
    {
        $request = StubJsonApiRequest::create(['page' => ['after' => '', 'size' => '5']]);

        $window = CursorPaginator::make()->window($request);

        self::assertNull($window->after);
        self::assertSame(5, $window->limit);
    }
}
