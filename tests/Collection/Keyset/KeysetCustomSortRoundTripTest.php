<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Collection\Keyset;

use haddowg\JsonApi\Collection\CursorCollectionResult;
use haddowg\JsonApi\Collection\Keyset\CursorTokenMinter;
use haddowg\JsonApi\Collection\Keyset\InMemoryKeyset;
use haddowg\JsonApi\Collection\Keyset\KeysetColumn;
use haddowg\JsonApi\Collection\Keyset\KeysetResolver;
use haddowg\JsonApi\Exception\CursorStale;
use haddowg\JsonApi\Pagination\CursorBoundary;
use haddowg\JsonApi\Pagination\CursorCodec;
use haddowg\JsonApi\Pagination\CursorWindow;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortFamily;
use haddowg\JsonApi\Resource\Sort\SortInterface;
use haddowg\JsonApi\Tests\Collection\Keyset\Fixture\MetaValueSort;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Cursor pages ordered by a custom {@see \haddowg\JsonApi\Resource\Sort\KeysetSortInterface}
 * resolved from a sort family, driven through the same resolve → sort → after → mint →
 * decode round trip a data layer runs.
 *
 * @internal
 */
#[CoversClass(KeysetResolver::class)]
#[CoversClass(InMemoryKeyset::class)]
#[CoversClass(CursorTokenMinter::class)]
#[CoversClass(KeysetColumn::class)]
#[Group('spec:pagination')]
final class KeysetCustomSortRoundTripTest extends TestCase
{
    #[Test]
    public function anAscendingCustomSortPagesWithNullsLast(): void
    {
        // cap asc, NULL=largest, id tiebreak: 2 (10), 5 (10), 4 (20), 1 (30), 3 (null).
        self::assertSame([[2, 5], [4, 1], [3]], $this->walk(['value.cap'], 2));
    }

    #[Test]
    public function aDescendingCustomSortPagesWithNullsFirst(): void
    {
        // cap desc puts null first, and the trailing descending directive makes the id
        // tiebreak descending too: 3 (null), 1 (30), 4 (20), 5 (10), 2 (10).
        self::assertSame([[3, 1], [4, 5], [2]], $this->walk(['-value.cap'], 2));
    }

    #[Test]
    public function aCustomSortMixesWithAFieldSort(): void
    {
        // title asc, then cap desc within a title: a (1: 30, 2: 10), b (3: null, 4: 20, 5: 10).
        self::assertSame([[1, 2], [3, 4], [5]], $this->walk(['title', '-value.cap'], 2));
    }

    #[Test]
    public function thePreviousTokenWalksBackAcrossTheCustomSort(): void
    {
        $sort = ['-value.cap'];
        $first = $this->fetch(new CursorWindow(2), $sort);
        $second = $this->fetch(new CursorWindow(2, after: $this->decode($first->cursorAfter, 'page[after]')), $sort);

        $back = $this->fetch(new CursorWindow(2, before: $this->decode($second->cursorBefore, 'page[before]')), $sort);

        self::assertSame([3, 1], $this->ids($back));
        self::assertFalse($back->hasPrevious);
    }

    #[Test]
    public function theTokenCarriesTheCustomValueUnderTheSortKey(): void
    {
        $first = $this->fetch(new CursorWindow(2), ['value.cap']);
        $after = $this->decode($first->cursorAfter, 'page[after]');

        self::assertSame(['value.cap' => 10, 'id' => 5], $after->values);
        self::assertSame(['value.cap' => false, 'id' => false], $after->descending);
    }

    #[Test]
    public function aDateTimeValueRoundTripsThroughTheToken(): void
    {
        // ends asc: 4 (Jan), 2 (Mar), 5 (Jun), 1 (Sep), 3 (null).
        self::assertSame([[4, 2], [5, 1], [3]], $this->walk(['value.ends'], 2));

        $first = $this->fetch(new CursorWindow(2), ['value.ends']);
        self::assertSame('2026-03-01T00:00:00.000000+00:00', $this->decode($first->cursorAfter, 'page[after]')->values['value.ends']);
    }

    #[Test]
    public function aCursorMintedUnderAnotherFamilyKeyIsStale(): void
    {
        $first = $this->fetch(new CursorWindow(2), ['value.cap']);

        $this->expectException(CursorStale::class);

        $this->fetch(new CursorWindow(2, after: $this->decode($first->cursorAfter, 'page[after]')), ['value.ends']);
    }

    #[Test]
    public function aBackedEnumCoercesToItsValue(): void
    {
        self::assertSame('b', CursorTokenMinter::coerce(Fixture\Grade::B));
    }

    /**
     * Pages forward from the first page until no further page follows.
     *
     * @param list<string> $sort
     *
     * @return list<list<int>>
     */
    private function walk(array $sort, int $limit): array
    {
        $pages = [];
        $window = new CursorWindow($limit);
        while (true) {
            $result = $this->fetch($window, $sort);
            $pages[] = $this->ids($result);
            if (!$result->hasMore) {
                return $pages;
            }
            $window = new CursorWindow($limit, after: $this->decode($result->cursorAfter, 'page[after]'));
        }
    }

    /**
     * @param list<string> $sort
     *
     * @return CursorCollectionResult<object>
     */
    private function fetch(CursorWindow $window, array $sort): CursorCollectionResult
    {
        $resolver = new KeysetResolver();
        $keyset = new InMemoryKeyset();
        $minter = new CursorTokenMinter(new CursorCodec());

        $columns = $resolver->resolve($sort, $this->sorts(), [], 'id', request: new JsonApiRequest(new ServerRequest('GET', '/campaigns')));

        $backward = $window->before !== null;
        $boundary = $backward ? $window->before : $window->after;
        $orderColumns = $backward
            ? \array_map(static fn(KeysetColumn $column): KeysetColumn => $column->flipped(), $columns)
            : $columns;

        if ($boundary !== null) {
            $resolver->assertFresh($boundary, $columns, $backward ? 'page[before]' : 'page[after]');
        }

        $sorted = $keyset->sort($this->rows(), $orderColumns);
        if ($boundary !== null) {
            $sorted = $keyset->after($sorted, $boundary, $orderColumns);
        }

        $hasSurplus = \count($sorted) > $window->limit;
        $page = \array_slice($sorted, 0, $window->limit);
        if ($backward) {
            $page = \array_reverse($page);
        }

        return $minter->mint(
            $window,
            $columns,
            \array_values($page),
            $hasSurplus,
            static fn(object $row, string $column): string|int|float|bool|null => CursorTokenMinter::coerce(Accessor::get($row, $column)),
        );
    }

    /**
     * @return list<SortInterface>
     */
    private function sorts(): array
    {
        return [
            SortByField::make('title'),
            SortFamily::make('value', static fn(string $suffix): SortInterface => new MetaValueSort('value.' . $suffix, $suffix))
                ->withSuffixPattern('[a-z_]+'),
        ];
    }

    /**
     * @return list<object>
     */
    private function rows(): array
    {
        return [
            $this->row(1, 'a', 30, '2026-09-01'),
            $this->row(2, 'a', 10, '2026-03-01'),
            $this->row(3, 'b', null, null),
            $this->row(4, 'b', 20, '2026-01-01'),
            $this->row(5, 'b', 10, '2026-06-01'),
        ];
    }

    private function row(int $id, string $title, ?int $cap, ?string $ends): object
    {
        return new class ($id, $title, ['cap' => $cap, 'ends' => $ends === null ? null : new \DateTimeImmutable($ends . 'T00:00:00+00:00')]) {
            /**
             * @param array<string, mixed> $meta
             */
            public function __construct(
                public readonly int $id,
                public readonly string $title,
                public readonly array $meta,
            ) {}
        };
    }

    private function decode(?string $token, string $parameter): CursorBoundary
    {
        self::assertNotNull($token);

        return (new CursorCodec())->decode($token, $parameter);
    }

    /**
     * @param CursorCollectionResult<object> $result
     *
     * @return list<int>
     */
    private function ids(CursorCollectionResult $result): array
    {
        $ids = [];
        foreach ($result->items as $item) {
            $id = Accessor::get($item, 'id');
            self::assertIsInt($id);
            $ids[] = $id;
        }

        return $ids;
    }
}
