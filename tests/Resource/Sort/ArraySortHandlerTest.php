<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Sort;

use haddowg\JsonApi\Resource\Sort\InMemory\ArraySortHandler;
use haddowg\JsonApi\Resource\Sort\Sort;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\UnsupportedSort;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ArraySortHandler::class)]
#[CoversClass(SortByField::class)]
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
            ['id' => '1', 'views' => 10],
            ['id' => '2', 'views' => 50],
            ['id' => '3', 'views' => 5],
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
        $result = (new ArraySortHandler())->apply(SortByField::make('views'), $this->data(), false);

        self::assertSame(['3', '1', '2'], $this->ids($result));
    }

    #[Test]
    public function descending(): void
    {
        $result = (new ArraySortHandler())->apply(SortByField::make('views'), $this->data(), true);

        self::assertSame(['2', '1', '3'], $this->ids($result));
    }

    #[Test]
    public function sortByMappedColumn(): void
    {
        $sort = SortByField::make('popularity', 'views');
        $result = (new ArraySortHandler())->apply($sort, $this->data(), true);

        self::assertSame('popularity', $sort->key());
        self::assertSame(['2', '1', '3'], $this->ids($result));
    }

    #[Test]
    public function unsupportedSortThrows500(): void
    {
        $sort = new class implements Sort {
            public function key(): string
            {
                return 'computed';
            }
        };

        try {
            (new ArraySortHandler())->apply($sort, $this->data(), false);
            self::fail('Expected UnsupportedSort.');
        } catch (UnsupportedSort $e) {
            self::assertSame(500, $e->getStatusCode());
            self::assertSame($sort, $e->sort);
            self::assertCount(1, $e->getErrors());
        }
    }
}
