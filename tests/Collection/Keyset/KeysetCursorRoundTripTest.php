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
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The keyset machinery composed end-to-end, exactly as an in-memory data layer
 * runs a cursor window (ADR 0123): resolve the keyset columns, check the cursor
 * against them (stale → 400), sort by the forced NULL=largest order, keep the
 * rows strictly after the boundary, over-fetch `limit + 1`, slice, re-orient a
 * backward page, and mint the boundary tokens off the sliced page. Drives the
 * real mint → decode → resolve → after round-trip through the shared
 * {@see KeysetResolver} / {@see InMemoryKeyset} / {@see CursorTokenMinter}, so
 * forward/backward navigation, the has-flags, NULL ordering, and the staleness
 * guard are all exercised end-to-end.
 *
 * @internal
 */
#[CoversClass(KeysetResolver::class)]
#[CoversClass(InMemoryKeyset::class)]
#[CoversClass(CursorTokenMinter::class)]
#[Group('spec:pagination')]
final class KeysetCursorRoundTripTest extends TestCase
{
    #[Test]
    public function itPagesForwardThroughAPkOnlyKeyset(): void
    {
        $songs = $this->songs();

        $first = $this->fetch($songs, new CursorWindow(2));

        self::assertSame([1, 2], $this->ids($first));
        self::assertTrue($first->hasMore);
        self::assertFalse($first->hasPrevious);
        self::assertNotNull($first->cursorAfter);

        $second = $this->fetch($songs, new CursorWindow(2, after: $this->decode($first->cursorAfter, 'page[after]')));

        self::assertSame([3], $this->ids($second));
        self::assertFalse($second->hasMore);
        self::assertTrue($second->hasPrevious);
    }

    #[Test]
    public function itPagesBackwardViaThePreviousToken(): void
    {
        $songs = $this->songs();

        $second = $this->fetch($songs, new CursorWindow(2, after: $this->decode(
            $this->fetch($songs, new CursorWindow(2))->cursorAfter,
            'page[after]',
        )));
        self::assertNotNull($second->cursorBefore);

        // Navigating back from the second page reproduces the first page's rows in
        // natural forward order (the backward page flips the order, slices, then reverses).
        $back = $this->fetch($songs, new CursorWindow(2, before: $this->decode($second->cursorBefore, 'page[before]')));

        self::assertSame([1, 2], $this->ids($back));
        self::assertTrue($back->hasMore);
        self::assertFalse($back->hasPrevious);
    }

    #[Test]
    public function itOrdersNullsLastUnderAnAscendingSortAndPagesPastThem(): void
    {
        $songs = $this->songs();
        $sorts = [SortByField::make('rating')];

        // rating asc, NULL=largest: 5.5 (2), 9.0 (1), null (3).
        $first = $this->fetch($songs, new CursorWindow(2), $sorts, ['rating']);
        self::assertSame([2, 1], $this->ids($first));
        self::assertTrue($first->hasMore);

        $second = $this->fetch($songs, new CursorWindow(2, after: $this->decode($first->cursorAfter, 'page[after]')), $sorts, ['rating']);
        self::assertSame([3], $this->ids($second));
    }

    #[Test]
    public function itRejectsACursorMintedUnderADifferentSortDirection(): void
    {
        $songs = $this->songs();
        $sorts = [SortByField::make('title')];

        $first = $this->fetch($songs, new CursorWindow(2), $sorts, ['title']);
        $after = $this->decode($first->cursorAfter, 'page[after]');

        // The client flipped `?sort=title` → `?sort=-title` while holding the cursor: the
        // resolved keyset direction no longer matches the token's, so it is stale.
        $this->expectException(CursorStale::class);

        $this->fetch($songs, new CursorWindow(2, after: $after), $sorts, ['-title']);
    }

    /**
     * @return list<object>
     */
    private function songs(): array
    {
        return [
            $this->song(1, 'The Article', 9.0),
            $this->song(2, 'Article Two', 5.5),
            $this->song(3, 'Zed', null),
        ];
    }

    private function song(int $id, string $title, ?float $rating): object
    {
        return new class ($id, $title, $rating) {
            public function __construct(
                public readonly int $id,
                public readonly string $title,
                public readonly ?float $rating,
            ) {}
        };
    }

    /**
     * Runs the canonical in-memory cursor (keyset) composition a data layer
     * executes for a {@see CursorWindow} — the recipe the machinery is designed
     * around (ADR 0123).
     *
     * @param list<object>        $items
     * @param list<SortInterface> $sorts the declared sort vocabulary
     * @param list<string>        $sort  the requested `sort` fields
     *
     * @return CursorCollectionResult<object>
     */
    private function fetch(array $items, CursorWindow $window, array $sorts = [], array $sort = []): CursorCollectionResult
    {
        $resolver = new KeysetResolver();
        $keyset = new InMemoryKeyset();
        $minter = new CursorTokenMinter(new CursorCodec());

        $columns = $resolver->resolve($sort, $sorts, [], 'id');

        // page[before] wins over page[after]: a backward page flips the column
        // directions and the after-predicate, so "strictly after under the
        // reversed order" means "strictly before under the natural order".
        $backward = $window->before !== null;
        $boundary = $backward ? $window->before : $window->after;
        $orderColumns = $backward ? $this->flip($columns) : $columns;

        if ($boundary !== null) {
            $resolver->assertFresh($boundary, $columns, $backward ? 'page[before]' : 'page[after]');
        }

        $sorted = $keyset->sort($items, $orderColumns);
        if ($boundary !== null) {
            $sorted = $keyset->after($sorted, $boundary, $orderColumns);
        }

        // Over-fetch by one: the surplus proves a further page (forward → next,
        // backward → prev). Slice to the limit, then re-orient a backward page to
        // natural forward order for rendering.
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
     * @param list<KeysetColumn> $columns
     *
     * @return list<KeysetColumn>
     */
    private function flip(array $columns): array
    {
        return \array_map(
            static fn(KeysetColumn $column): KeysetColumn => new KeysetColumn($column->column, !$column->descending),
            $columns,
        );
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
