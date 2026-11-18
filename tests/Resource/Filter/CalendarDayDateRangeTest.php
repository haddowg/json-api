<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Filter;

use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Constraint\Pattern;
use haddowg\JsonApi\Resource\Field\Date;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Filter\DateRange;
use haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A DateRange over a date column takes a full date for each bound, is documented as
 * `format: date`, and compares calendar days rather than instants.
 */
#[CoversClass(DateRange::class)]
#[CoversClass(ArrayFilterHandler::class)]
#[CoversClass(AbstractResource::class)]
final class CalendarDayDateRangeTest extends TestCase
{
    private string $defaultZone;

    protected function setUp(): void
    {
        $this->defaultZone = \date_default_timezone_get();
        \date_default_timezone_set('America/New_York');
    }

    protected function tearDown(): void
    {
        \date_default_timezone_set($this->defaultZone);
    }

    #[Test]
    public function aDateTimeRangeDocumentsAndAcceptsADateTime(): void
    {
        $filter = DateRange::make('published')->build();

        self::assertFalse($filter->calendarDays);
        self::assertSame('date-time', $this->boundFormat($filter));
        self::assertSame('Matches values within the given inclusive date-time range.', $filter->getDescription());
        self::assertSame(1, \preg_match($this->regex($filter), '2026-10-01T00:00:00Z'));
        self::assertSame(0, \preg_match($this->regex($filter), '2026-10-01'));
    }

    #[Test]
    public function aCalendarDayRangeDocumentsAndAcceptsAFullDate(): void
    {
        $filter = DateRange::make('day')->calendarDays()->build();

        self::assertTrue($filter->calendarDays);
        self::assertSame('date', $this->boundFormat($filter));
        self::assertSame('Matches values within the given inclusive date range.', $filter->getDescription());

        $regex = $this->regex($filter);
        self::assertSame(1, \preg_match($regex, '2026-10-01'));
        foreach (['2026-10-01T00:00:00Z', '2026-10-01 00:00', 'yesterday', '01/10/2026'] as $refused) {
            self::assertSame(0, \preg_match($regex, $refused), $refused);
        }
    }

    #[Test]
    public function anAuthorsOwnDescriptionIsKept(): void
    {
        $filter = DateRange::make('day')->describedAs('On these days.')->calendarDays()->build();

        self::assertSame('On these days.', $filter->getDescription());
        self::assertSame('On these days.', DateRange::make('day')->describedAs('On these days.')->build()->withCalendarDays()->getDescription());
    }

    #[Test]
    public function aResourceComparesDaysOverAColumnOneDateFieldBacks(): void
    {
        $filters = [];
        foreach ($this->resource()->allFilters() as $filter) {
            self::assertInstanceOf(DateRange::class, $filter);
            $filters[$filter->key()] = $filter->calendarDays;
        }

        self::assertSame([
            'dayRange' => true,
            'dateFormattedRange' => true,
            'startsRange' => false,
            'sharedRange' => false,
            'unbackedRange' => false,
        ], $filters);
    }

    #[Test]
    public function theInMemoryHandlerComparesCalendarDays(): void
    {
        // Each stored day is midnight in New York, 04:00 or 05:00 UTC.
        $rows = [
            ['id' => '1', 'day' => new \DateTimeImmutable('2026-10-01')],
            ['id' => '2', 'day' => new \DateTimeImmutable('2026-09-30')],
            ['id' => '3', 'day' => '2026-10-02'],
            ['id' => 'N', 'day' => null],
        ];
        $filter = DateRange::make('day')->calendarDays()->build();

        self::assertSame(['1', '3'], $this->ids((new ArrayFilterHandler())->apply($filter, $rows, ['min' => '2026-10-01'])));
        self::assertSame(['1', '2'], $this->ids((new ArrayFilterHandler())->apply($filter, $rows, ['max' => '2026-10-01'])));
        self::assertSame(['1'], $this->ids((new ArrayFilterHandler())->apply($filter, $rows, ['min' => '2026-10-01', 'max' => '2026-10-01'])));

        // Read in UTC the same days still compare as days.
        \date_default_timezone_set('UTC');
        self::assertSame(['1', '2'], $this->ids((new ArrayFilterHandler())->apply($filter, $rows, ['max' => '2026-10-01'])));
    }

    private function resource(): AbstractResource
    {
        return new class extends AbstractResource {
            public static string $type = 'shifts';

            public function fields(): array
            {
                return [
                    Id::make(),
                    Date::make('day'),
                    DateTime::make('dateFormatted')->format('Y-m-d'),
                    DateTime::make('startsAt'),
                    Date::make('sharedDay')->storedAs('shared'),
                    DateTime::make('sharedAt')->storedAs('shared'),
                ];
            }

            public function filters(): array
            {
                return [
                    DateRange::make('dayRange', 'day'),
                    DateRange::make('dateFormattedRange', 'dateFormatted'),
                    DateRange::make('startsRange', 'startsAt'),
                    DateRange::make('sharedRange', 'shared'),
                    DateRange::make('unbackedRange', 'unbacked'),
                ];
            }
        };
    }

    private function boundFormat(DateRange $filter): mixed
    {
        $schema = $filter->describeQueryParameter(Schema::ofType('string'))->schema->toArray();
        self::assertIsArray($schema['properties'] ?? null);
        self::assertIsArray($schema['properties']['min'] ?? null);

        return $schema['properties']['min']['format'] ?? null;
    }

    private function regex(DateRange $filter): string
    {
        $pattern = $filter->constraints[0] ?? null;
        self::assertInstanceOf(Pattern::class, $pattern);

        return '/' . $pattern->regex . '/';
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
}
