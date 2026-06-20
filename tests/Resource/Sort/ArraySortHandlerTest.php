<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Sort;

use haddowg\JsonApi\Resource\Sort\InMemory\ArraySortArmInterface;
use haddowg\JsonApi\Resource\Sort\InMemory\ArraySortHandler;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortDirective;
use haddowg\JsonApi\Resource\Sort\SortInterface;
use haddowg\JsonApi\Resource\Sort\UnsupportedSort;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ArraySortHandler::class)]
#[CoversClass(SortByField::class)]
#[CoversClass(SortDirective::class)]
#[CoversClass(UnsupportedSort::class)]
#[Group('spec:sorting')]
final class ArraySortHandlerTest extends TestCase
{
    /**
     * @return list<array<string, mixed>>
     */
    private function data(): array
    {
        return [
            ['id' => '1', 'views' => 10, 'category' => 'news'],
            ['id' => '2', 'views' => 50, 'category' => 'guide'],
            ['id' => '3', 'views' => 5, 'category' => 'news'],
        ];
    }

    /**
     * @return list<string>
     */
    private function ids(mixed $result): array
    {
        self::assertIsArray($result);
        $ids = [];
        foreach ($result as $row) {
            self::assertIsArray($row);
            self::assertIsString($row['id']);
            $ids[] = $row['id'];
        }

        return $ids;
    }

    #[Test]
    public function ascending(): void
    {
        $result = (new ArraySortHandler())->apply(
            [new SortDirective(SortByField::make('views'), false)],
            $this->data(),
        );

        self::assertSame(['3', '1', '2'], $this->ids($result));
    }

    #[Test]
    public function descending(): void
    {
        $result = (new ArraySortHandler())->apply(
            [new SortDirective(SortByField::make('views'), true)],
            $this->data(),
        );

        self::assertSame(['2', '1', '3'], $this->ids($result));
    }

    #[Test]
    public function sortByMappedColumn(): void
    {
        $sort = SortByField::make('popularity', 'views');
        $result = (new ArraySortHandler())->apply([new SortDirective($sort, true)], $this->data());

        self::assertSame('popularity', $sort->key());
        self::assertSame(['2', '1', '3'], $this->ids($result));
    }

    #[Test]
    public function theFirstDirectiveIsThePrimarySortKey(): void
    {
        // category asc groups guide(2) before news(1, 3); views desc breaks the
        // news tie as 1 (10) before 3 (5). A directive-by-directive re-sort
        // would instead let the last key dominate — this pins the cascade.
        $result = (new ArraySortHandler())->apply(
            [
                new SortDirective(SortByField::make('category'), false),
                new SortDirective(SortByField::make('views'), true),
            ],
            $this->data(),
        );

        self::assertSame(['2', '1', '3'], $this->ids($result));

        $reversed = (new ArraySortHandler())->apply(
            [
                new SortDirective(SortByField::make('category'), false),
                new SortDirective(SortByField::make('views'), false),
            ],
            $this->data(),
        );

        self::assertSame(['2', '3', '1'], $this->ids($reversed));
    }

    #[Test]
    public function anEmptySortOrderIsANoOp(): void
    {
        $result = (new ArraySortHandler())->apply([], $this->data());

        self::assertSame(['1', '2', '3'], $this->ids($result));
    }

    #[Test]
    public function unsupportedSortThrows500(): void
    {
        $sort = new class implements \haddowg\JsonApi\Resource\Sort\SortInterface {
            public function key(): string
            {
                return 'computed';
            }
        };

        try {
            (new ArraySortHandler())->apply([new SortDirective($sort, false)], $this->data());
            self::fail('Expected UnsupportedSort.');
        } catch (UnsupportedSort $e) {
            self::assertSame(500, $e->getStatusCode());
            self::assertSame($sort, $e->sort);
            self::assertCount(1, $e->getErrors());
        }
    }

    #[Test]
    public function customSortRunsThroughARegisteredArm(): void
    {
        // key = strlen(category): news=4 (rows 1,3), guide=5 (row 2).
        $arm = $this->categoryLengthArm();

        $ascending = (new ArraySortHandler([$arm]))->apply(
            [new SortDirective($this->bespokeSort('categoryLength'), false)],
            $this->data(),
        );
        self::assertSame(['1', '3', '2'], $this->ids($ascending));

        $descending = (new ArraySortHandler([$arm]))->apply(
            [new SortDirective($this->bespokeSort('categoryLength'), true)],
            $this->data(),
        );
        self::assertSame(['2', '1', '3'], $this->ids($descending));
    }

    #[Test]
    public function aCustomSortWeavesIntoTheCascadeLikeAFieldSort(): void
    {
        // categoryLength asc is the primary key (news rows 1,3 before guide row 2);
        // views asc breaks the news tie as 3 (5) before 1 (10) — proving the arm's
        // key participates in the lexicographic cascade rather than re-sorting.
        $result = (new ArraySortHandler([$this->categoryLengthArm()]))->apply(
            [
                new SortDirective($this->bespokeSort('categoryLength'), false),
                new SortDirective(SortByField::make('views'), false),
            ],
            $this->data(),
        );

        self::assertSame(['3', '1', '2'], $this->ids($result));
    }

    private function categoryLengthArm(): ArraySortArmInterface
    {
        return new class implements ArraySortArmInterface {
            public function supports(SortInterface $sort): bool
            {
                return $sort->key() === 'categoryLength';
            }

            public function value(SortInterface $sort, mixed $row): mixed
            {
                $category = \is_array($row) ? ($row['category'] ?? '') : '';

                return \is_string($category) ? \strlen($category) : 0;
            }
        };
    }

    private function bespokeSort(string $key): SortInterface
    {
        return new class ($key) implements SortInterface {
            public function __construct(private readonly string $key) {}

            public function key(): string
            {
                return $this->key;
            }
        };
    }
}
