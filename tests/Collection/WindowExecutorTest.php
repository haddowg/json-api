<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Collection;

use haddowg\JsonApi\Collection\WindowExecutor;
use haddowg\JsonApi\Pagination\OffsetWindow;
use haddowg\JsonApi\Pagination\WindowInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:pagination')]
final class WindowExecutorTest extends TestCase
{
    #[Test]
    public function unwindowedReturnsAllItemsWithNoCount(): void
    {
        $all = [$this->item('a'), $this->item('b'), $this->item('c')];

        $result = (new WindowExecutor())->run(
            window: null,
            countable: true,
            all: static fn(): array => $all,
            count: $this->unusedCount(),
            page: $this->unusedPage(),
            probe: $this->unusedPage(),
        );

        self::assertSame($all, $result->items);
        self::assertNull($result->total);
        self::assertFalse($result->windowed);
        self::assertFalse($result->hasMore);
    }

    #[Test]
    public function offsetWindowCountableCountsAndPages(): void
    {
        $page = [$this->item('b'), $this->item('c')];

        $result = (new WindowExecutor())->run(
            window: new OffsetWindow(1, 2),
            countable: true,
            all: $this->unusedAll(),
            count: static fn(): int => 5,
            page: static function (int $offset, int $limit) use ($page): array {
                self::assertSame(1, $offset);
                self::assertSame(2, $limit);

                return $page;
            },
            probe: $this->unusedPage(),
        );

        self::assertSame($page, $result->items);
        self::assertSame(5, $result->total);
        self::assertTrue($result->windowed);
        self::assertFalse($result->hasMore);
    }

    #[Test]
    public function countFreeWindowProbesLimitPlusOneAndSetsHasMoreTrueWhenSurplusReturned(): void
    {
        $a = $this->item('a');
        $b = $this->item('b');
        $c = $this->item('c');

        // probe returns limit + 1 (3) items for a limit of 2 -> a further page follows.
        $result = (new WindowExecutor())->run(
            window: new OffsetWindow(0, 2),
            countable: false,
            all: $this->unusedAll(),
            count: $this->unusedCount(),
            page: $this->unusedPage(),
            probe: static function (int $offset, int $limit) use ($a, $b, $c): array {
                self::assertSame(0, $offset);
                self::assertSame(3, $limit); // limit + 1

                return [$a, $b, $c];
            },
        );

        self::assertSame([$a, $b], $result->items); // surplus dropped
        self::assertNull($result->total);
        self::assertTrue($result->windowed);
        self::assertTrue($result->hasMore);
    }

    #[Test]
    public function countFreeWindowSetsHasMoreFalseWhenProbeReturnsExactlyLimit(): void
    {
        $page = [$this->item('a'), $this->item('b')];

        // probe returns exactly limit (2) items at the boundary -> no further page.
        $result = (new WindowExecutor())->run(
            window: new OffsetWindow(0, 2),
            countable: false,
            all: $this->unusedAll(),
            count: $this->unusedCount(),
            page: $this->unusedPage(),
            probe: static fn(int $offset, int $limit): array => $page,
        );

        self::assertSame($page, $result->items);
        self::assertNull($result->total);
        self::assertTrue($result->windowed);
        self::assertFalse($result->hasMore);
    }

    #[Test]
    public function nonOffsetWindowThrowsLogicException(): void
    {
        $window = new class implements WindowInterface {};

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('can only execute');

        (new WindowExecutor())->run(
            window: $window,
            countable: true,
            all: $this->unusedAll(),
            count: $this->unusedCount(),
            page: $this->unusedPage(),
            probe: $this->unusedPage(),
        );
    }

    /**
     * A distinguishable stub item carrying a label, so `assertSame` over identity
     * proves the executor returned the exact objects the closures produced.
     */
    private function item(string $label): object
    {
        return new class ($label) {
            public function __construct(public string $label) {}
        };
    }

    /**
     * An `all` closure that must never run for the asserted branch.
     *
     * @return \Closure(): list<object>
     */
    private function unusedAll(): \Closure
    {
        return function (): array {
            self::fail('the all closure must not run for this branch');
        };
    }

    /**
     * A `count` closure that must never run for the asserted branch.
     *
     * @return \Closure(): int
     */
    private function unusedCount(): \Closure
    {
        return function (): int {
            self::fail('the count closure must not run for this branch');
        };
    }

    /**
     * A `page`/`probe` closure that must never run for the asserted branch.
     *
     * @return \Closure(int, int): list<object>
     */
    private function unusedPage(): \Closure
    {
        return function (int $offset, int $limit): array {
            self::fail('the page/probe closure must not run for this branch');
        };
    }
}
