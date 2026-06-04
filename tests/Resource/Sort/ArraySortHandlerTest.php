<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Sort;

use haddowg\JsonApi\Resource\Sort\InMemory\ArraySortHandler;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortDirective;
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
}
