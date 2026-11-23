<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Filter;

use haddowg\JsonApi\Resource\Field\Date;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\Time;
use haddowg\JsonApi\Resource\Filter\Contains;
use haddowg\JsonApi\Resource\Filter\DateFilterValue;
use haddowg\JsonApi\Resource\Filter\DateOperand;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterHandler;
use haddowg\JsonApi\Resource\Filter\ThroughPathResolver;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereIn;
use haddowg\JsonApi\Resource\Filter\WhereNotIn;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A comparison or list filter over a date field reads each value as that field's date, and
 * the reference in-memory handler compares it with the stored date as a database compares
 * the column: by day, by time of day or as an instant.
 */
#[CoversClass(DateOperand::class)]
#[CoversClass(DateFilterValue::class)]
#[CoversClass(ArrayFilterHandler::class)]
#[CoversClass(ThroughPathResolver::class)]
final class DateOperandTest extends TestCase
{
    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(): array
    {
        return [
            ['id' => 1, 'startsAt' => new \DateTimeImmutable('2026-10-01T09:00:00Z'), 'day' => new \DateTimeImmutable('2026-10-01 00:00:00', new \DateTimeZone('America/New_York')), 'breakAt' => new \DateTimeImmutable('1970-01-01 12:30:00'), 'title' => 'a'],
            ['id' => 2, 'startsAt' => new \DateTimeImmutable('2026-10-02T15:00:00Z'), 'day' => new \DateTimeImmutable('2026-10-02'), 'breakAt' => new \DateTimeImmutable('2026-11-23 14:00:00'), 'title' => 'b'],
        ];
    }

    /**
     * @return iterable<string, array{FilterInterface, string, list<int>}>
     */
    public static function filters(): iterable
    {
        yield 'a date-time equal to the same instant in another offset' => [Where::make('startsAt')->build(), '2026-10-01T11:00:00+02:00', [1]];
        yield 'a date-time after an instant' => [Where::make('after', 'startsAt', '>')->build(), '2026-10-01T12:00:00Z', [2]];
        yield 'a date-time on or before an instant' => [Where::make('until', 'startsAt', '<=')->build(), '2026-10-01T09:00:00Z', [1]];
        yield 'a date by its day, whatever zone it is stored in' => [Where::make('day')->build(), '2026-10-01', [1]];
        yield 'a date not on a day' => [Where::make('notDay', 'day', '!=')->build(), '2026-10-01', [2]];
        yield 'a time by its time of day, whatever day it is stored on' => [Where::make('breakAt')->build(), '14:00:00', [2]];
        yield 'a set of dates' => [WhereIn::make('days', 'day')->build(), '2026-10-01,2026-10-02', [1, 2]];
        yield 'a set of date-times' => [WhereIn::make('starts', 'startsAt')->build(), '2026-10-02T17:00:00+02:00', [2]];
        yield 'dates not in a set' => [WhereNotIn::make('notDays', 'day')->build(), '2026-10-02', [1]];
    }

    /**
     * @param list<int> $ids
     */
    #[Test]
    #[DataProvider('filters')]
    public function aDateFilterComparesTheStoredDateAsADatabaseDoes(FilterInterface $filter, string $value, array $ids): void
    {
        $fields = [DateTime::make('startsAt')->build(), Date::make('day')->build(), Time::make('breakAt')->build(), Str::make('title')->build()];
        $resolved = (new ThroughPathResolver(static fn(string $type): ?array => null))->resolve($filter, $fields);

        $matched = (new ArrayFilterHandler())->apply($resolved, self::rows(), $value);

        self::assertIsArray($matched);
        self::assertSame($ids, \array_map(static fn(mixed $row): mixed => \is_array($row) ? $row['id'] : null, $matched));
    }

    #[Test]
    public function aFilterWithoutADateFieldOrWithItsOwnDeserializerIsLeftAsItIs(): void
    {
        $fields = [DateTime::make('startsAt')->build(), Str::make('title')->build()];
        $own = Where::make('startsAt')->deserializeUsing(static fn(mixed $value): mixed => $value)->build();

        foreach ([Where::make('title')->build(), Contains::make('like', 'startsAt')->build(), $own] as $filter) {
            self::assertSame($filter, DateFilterValue::withDateOperands($filter, $fields));
        }
    }

    #[Test]
    public function anOperandKeepsItsKindAndInstant(): void
    {
        $operand = DateFilterValue::operand(Date::make('day')->build(), '2026-10-01');

        self::assertInstanceOf(DateOperand::class, $operand);
        self::assertSame('date', $operand->kind);
        self::assertSame('2026-10-01', $operand->comparable(new \DateTimeImmutable('2026-10-01T23:00:00-05:00')));
        // A value the field does not take is left for validation to refuse.
        self::assertSame('banana', DateFilterValue::operand(Date::make('day')->build(), 'banana'));
    }
}
